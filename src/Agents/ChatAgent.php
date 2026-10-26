<?php

namespace Devletes\Sidekick\Agents;

use Devletes\Sidekick\Models\PendingAction;
use Devletes\Sidekick\Support\ToolRegistry;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/** Instructions must not change between turns (per-turn data goes in turnContext()) or the prompt cache misses. */
#[CacheToolDefinitions]
#[CacheInstructions]
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

        $guidance = app(ToolRegistry::class)->instructionsFor($user);

        $prompt .= "\n\nA user message may end with an app context note, written by the app when that message was sent, never by the user."
            .' Only the note on the latest message describes things as they are now; an earlier one is how they stood then.';

        return $guidance === '' ? $prompt : $prompt."\n\n".$guidance;
    }

    /** Sent with the user's message, not the instructions, so the instructions stay cacheable. */
    public function turnContext(): string
    {
        return collect([
            app(ToolRegistry::class)->turnContextFor($this->conversationParticipant()),
            $this->recentActionOutcomes(),
        ])->filter()->join("\n\n");
    }

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
            : "System-verified outcomes of recently proposed actions (the user confirms or cancels these in the panel — never claim an action happened unless listed here):\n".$outcomes;
    }

    public function tools(): iterable
    {
        return app(ToolRegistry::class)->offeredTo($this->conversationParticipant());
    }

    public function providerOptions(Lab|string $provider): array
    {
        $options = ['max_tokens' => (int) config('sidekick.max_output_tokens', 2048)];

        // Anthropic's automatic cache point on the last block, so each step and turn reads the prior history cached.
        if (config('sidekick.cache_history', true) && ($provider instanceof Lab ? $provider->value : $provider) === Lab::Anthropic->value) {
            $options['cache_control'] = ['type' => 'ephemeral'];
        }

        return $options;
    }

    protected function maxConversationMessages(): int
    {
        $limit = config('sidekick.history_limit', 10);

        // null means no cap; laravel/ai requires an int, so use PHP_INT_MAX.
        return $limit === null ? PHP_INT_MAX : max(1, (int) $limit);
    }
}
