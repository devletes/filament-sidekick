<?php

use Devletes\Sidekick\Storage\LeanConversationStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\ToolResultMessage;

beforeEach(function () {
    $this->artisan('migrate');

    // The messages table as laravel/ai 0.10–0.11 and this package's earlier migrations left it.
    Schema::drop('agent_conversation_messages');
    Schema::create('agent_conversation_messages', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->string('conversation_id', 36)->index();
        $table->string('participant_type')->nullable();
        $table->unsignedBigInteger('participant_id')->nullable();
        $table->foreignId('user_id')->nullable();
        $table->string('agent');
        $table->string('role', 25);
        $table->text('content');
        $table->text('context')->nullable();
        $table->text('attachments');
        $table->text('tool_calls');
        $table->text('tool_results');
        $table->text('usage');
        $table->text('meta');
        $table->text('approval_state')->nullable();
        $table->timestamps();

        $table->index(['participant_type', 'participant_id'], 'participant_index');
    });
});

function legacyMessage(string $conversationId, string $role, string $content, array $calls = [], array $results = [], array $meta = []): void
{
    static $second = 0;

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => 'test',
        'role' => $role,
        'content' => $content,
        'attachments' => '[]',
        'tool_calls' => json_encode($calls),
        'tool_results' => json_encode($results),
        'usage' => '[]',
        'meta' => json_encode($meta),
        'created_at' => now()->addSeconds(++$second),
        'updated_at' => now()->addSeconds($second),
    ]);
}

function runStepsMigration(): void
{
    (require __DIR__.'/../../database/migrations/2026_10_25_000100_store_conversation_messages_as_steps.php')->up();
}

it('rewrites 0.x rows as steps and drops the old columns', function () {
    $id = (string) Str::uuid7();

    legacyMessage($id, 'user', 'How much leave do I have?');
    legacyMessage($id, 'assistant', 'You have 12 days.',
        calls: [['id' => 'call_1', 'name' => 'LeaveBalances', 'arguments' => []]],
        results: [['id' => 'call_1', 'name' => 'LeaveBalances', 'arguments' => [], 'result' => '12 days']],
        meta: ['reasoning' => 'Checked the balance.', 'sidekick_ack' => false],
    );
    legacyMessage($id, 'assistant', 'Done — the request was sent.', meta: ['sidekick_ack' => true]);

    runStepsMigration();

    expect(Schema::hasColumns('agent_conversation_messages', ['steps', 'status']))->toBeTrue()
        ->and(Schema::hasColumn('agent_conversation_messages', 'tool_calls'))->toBeFalse()
        ->and(Schema::hasColumn('agent_conversation_messages', 'tool_results'))->toBeFalse()
        ->and(Schema::hasColumn('agent_conversation_messages', 'approval_state'))->toBeFalse();

    $rows = DB::table('agent_conversation_messages')->orderBy('id')->get();

    expect(json_decode($rows[0]->steps, true))->toBe([])
        ->and($rows[1]->status)->toBe('completed');

    $steps = json_decode($rows[1]->steps, true);

    expect($steps)->toHaveCount(2)
        ->and($steps[0]['tool_calls'][0]['result'])->toBe('12 days')
        ->and($steps[1]['content'])->toBe('You have 12 days.')
        ->and($steps[1]['reasoning'])->toBe('Checked the balance.')
        ->and(json_decode($rows[1]->meta, true))->not->toHaveKey('reasoning')
        ->and(json_decode($rows[2]->steps, true)[0]['content'])->toBe('Done — the request was sent.');

    $messages = app(LeanConversationStore::class)->getLatestConversationMessages($id, 10)->values();

    expect($messages)->toHaveCount(5)
        ->and($messages[1]->toolCalls->pluck('name')->all())->toBe(['LeaveBalances'])
        ->and($messages[2])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[3]->content)->toBe('You have 12 days.')
        ->and($messages[4]->content)->toBe('Done — the request was sent.');
});

it('leaves a table that already stores steps alone', function () {
    Schema::drop('agent_conversation_messages');
    Schema::create('agent_conversation_messages', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->longText('steps');
        $table->string('status', 25);
    });

    runStepsMigration();

    expect(Schema::hasColumns('agent_conversation_messages', ['steps', 'status']))->toBeTrue()
        ->and(Schema::hasColumn('agent_conversation_messages', 'tool_calls'))->toBeFalse();
});
