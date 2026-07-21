<?php

namespace Devletes\Sidekick\Agents;

use Devletes\Sidekick\Support\ToolRegistry;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

class ChatAgent implements Agent, Conversational, HasProviderOptions, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        $assistant = config('sidekick.assistant.name', 'Assistant');
        $user = $this->conversationParticipant();
        $name = $user->name ?? null;

        $base = "You are {$assistant}, an in-app assistant."
            .($name ? " You are talking to {$name}." : '')
            .' Be concise and direct. Answer in the language the user writes in.'
            .' Today is '.now()->toFormattedDateString().'.';

        $extra = config('sidekick.instructions');

        return ($extra ? $base."\n\n".$extra : $base).$this->recentActionOutcomes();
    }

    /** System-verified outcomes of confirmable actions, so the model knows what actually happened. */
    protected function recentActionOutcomes(): string
    {
        if (! $this->currentConversation()) {
            return '';
        }

        $outcomes = \Devletes\Sidekick\Models\PendingAction::query()
            ->where('conversation_id', $this->currentConversation())
            ->where('updated_at', '>=', now()->subHours(2))
            ->whereIn('status', [
                \Devletes\Sidekick\Models\PendingAction::STATUS_EXECUTED,
                \Devletes\Sidekick\Models\PendingAction::STATUS_CANCELLED,
                \Devletes\Sidekick\Models\PendingAction::STATUS_FAILED,
                \Devletes\Sidekick\Models\PendingAction::STATUS_EXPIRED,
            ])
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn ($action): string => "- {$action->summary}: {$action->status}"
                .($action->result ? " ({$action->result})" : ''))
            ->join("\n");

        return $outcomes === ''
            ? ''
            : "\n\nSystem-verified outcomes of recently proposed actions (the user confirms or cancels these in the panel — never claim an action happened unless listed here):\n".$outcomes;
    }

    public function tools(): iterable
    {
        return app(ToolRegistry::class)->authorizedFor($this->conversationParticipant());
    }

    public function providerOptions(\Laravel\Ai\Enums\Lab|string $provider): array
    {
        return ['max_tokens' => (int) config('sidekick.max_output_tokens', 2048)];
    }

    protected function maxConversationMessages(): int
    {
        $limit = config('sidekick.history_limit', 10);

        // null → the whole conversation enters context. laravel/ai types the
        // limit as int all the way down, so "no cap" travels as PHP_INT_MAX
        // (a valid LIMIT for MySQL and SQLite alike).
        return $limit === null ? PHP_INT_MAX : max(1, (int) $limit);
    }
}
