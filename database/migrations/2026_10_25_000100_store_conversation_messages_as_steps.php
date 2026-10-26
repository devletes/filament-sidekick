<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $messages = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        // One-way rewrite of pre-1.0 tool_calls/tool_results into steps; tables that already have steps are skipped.
        if (! Schema::hasTable($messages) || Schema::hasColumn($messages, 'steps') || ! Schema::hasColumn($messages, 'tool_calls')) {
            return;
        }

        Schema::table($messages, function (Blueprint $table) {
            $table->longText('steps')->nullable();
            $table->string('status', 25)->default('completed');
        });

        DB::table($messages)->where('role', 'user')->update(['steps' => '[]']);

        DB::table($messages)
            ->select('conversation_id')
            ->distinct()
            ->orderBy('conversation_id')
            ->chunk(100, function (Collection $conversations) use ($messages) {
                foreach ($conversations as $conversation) {
                    $this->backfill($messages, $conversation->conversation_id);
                }
            });

        $dropped = array_values(array_filter(
            ['tool_calls', 'tool_results', 'approval_state'],
            fn (string $column): bool => Schema::hasColumn($messages, $column),
        ));

        Schema::table($messages, function (Blueprint $table) use ($messages, $dropped) {
            $table->longText('steps')->nullable(false)->change();
            $table->dropColumn($dropped);

            // 1.x scopes a participant's latest conversation to the agent.
            if (Schema::hasIndex($messages, 'participant_index')) {
                $table->dropIndex('participant_index');
            }

            if (Schema::hasColumn($messages, 'participant_id')) {
                $table->index(['participant_type', 'participant_id', 'agent'], 'participant_index');
            }
        });
    }

    public function down(): void
    {
        // One-way: the old columns cannot be rebuilt faithfully from steps.
    }

    protected function backfill(string $table, string $conversationId): void
    {
        $rows = DB::table($table)
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->orderBy('id')
            ->get();

        // A result was recorded on the row of the request that produced it, which may be a later row than its call.
        $results = $rows->flatMap(fn (object $row) => $this->decoded($row->tool_results))->keyBy('id');

        foreach ($rows as $row) {
            $meta = $this->decoded($row->meta);

            $calls = collect($this->decoded($row->tool_calls))
                ->filter(fn ($call) => is_array($call) && $results->has($call['id'] ?? ''))
                ->map(fn (array $call) => [
                    ...$call,
                    'result' => $results[$call['id']]['result'] ?? null,
                    ...array_filter([
                        'denied' => $results[$call['id']]['denied'] ?? false,
                        'failed' => $results[$call['id']]['failed'] ?? false,
                    ]),
                ])
                ->values()
                ->all();

            $content = (string) $row->content;
            $reasoning = (string) ($meta['reasoning'] ?? '');

            $steps = $calls !== [] && $content !== ''
                ? [$this->step('', $calls), $this->step($content, [], $reasoning)]
                : [$this->step($content, $calls, $reasoning)];

            unset($meta['provider_steps'], $meta['provider_content_blocks'], $meta['reasoning']);

            DB::table($table)->where('id', $row->id)->update([
                'steps' => json_encode($steps),
                'meta' => json_encode($meta),
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $calls
     * @return array<string, mixed>
     */
    protected function step(string $content, array $calls = [], string $reasoning = ''): array
    {
        return [
            'content' => $content,
            'tool_calls' => $calls,
            'reasoning' => $reasoning,
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ];
    }

    /** @return array<array-key, mixed> */
    protected function decoded(?string $json): array
    {
        return is_array($decoded = json_decode($json ?? '', true)) ? $decoded : [];
    }
};
