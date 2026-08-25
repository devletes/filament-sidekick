<?php

namespace Devletes\Sidekick\Agents;

use Devletes\Sidekick\Models\PendingAction;
use Devletes\Sidekick\Support\ToolRegistry;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
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
        $prompt = $extra ? $base."\n\n".$extra : $base;

        // Standing guidance the offered tools ship with (ChatTool::instructions()).
        $guidance = app(ToolRegistry::class)->instructionsFor($user);

        return ($guidance === '' ? $prompt : $prompt."\n\n".$guidance).$this->recentActionOutcomes();
    }

    /** System-verified outcomes of confirmable actions, so the model knows what actually happened. */
    protected function recentActionOutcomes(): string
    {
        if (! $this->currentConversation()) {
            return '';
        }

        $outcomes = PendingAction::query()
            ->where('conversation_id', $this->currentConversation())
            ->where('updated_at', '>=', now()->subHours(2))
            ->whereIn('status', [
                PendingAction::STATUS_EXECUTED,
                PendingAction::STATUS_CANCELLED,
                PendingAction::STATUS_FAILED,
                PendingAction::STATUS_EXPIRED,
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

    public function providerOptions(Lab|string $provider): array
    {
        return ['max_tokens' => (int) config('sidekick.max_output_tokens', 2048)];
    }

    protected function maxConversationMessages(): int
    {
        $limit = config('sidekick.history_limit', 10);

        // null means no cap; laravel/ai types the limit as int, so it travels as PHP_INT_MAX (a valid LIMIT everywhere).
        return $limit === null ? PHP_INT_MAX : max(1, (int) $limit);
    }
}
