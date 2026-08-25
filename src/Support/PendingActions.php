<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\ProposableAction;
use Devletes\Sidekick\Enums\ConfirmationMode;
use Devletes\Sidekick\Models\PendingAction;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

/** Validates proposals via the handler and stores the card; execution only ever happens from the panel's Confirm. */
class PendingActions
{
    public function __construct(protected ActionRegistry $registry) {}

    /** @return array The stored proposal (for the tool's response to the model). */
    public function propose(string $type, array $payload, Authenticatable $user): array
    {
        $handler = $this->registry->handler($type);

        if (! $handler) {
            throw new InvalidArgumentException("Unknown action type [{$type}].");
        }

        $prepared = $handler->prepare($payload, $user);

        $context = app(RunContext::class);

        // One live card at a time: a newer proposal supersedes any unanswered one.
        if ($context->conversationId) {
            PendingAction::query()
                ->where('conversation_id', $context->conversationId)
                ->where('status', PendingAction::STATUS_PROPOSED)
                ->update([
                    'status' => PendingAction::STATUS_CANCELLED,
                    'result' => 'Superseded by a newer proposal.',
                ]);
        }

        $action = PendingAction::create([
            'conversation_id' => $context->conversationId ?? '',
            'run_id' => $context->runId,
            'user_id' => $user->getAuthIdentifier(),
            'type' => $type,
            'payload' => $prepared['payload'],
            'preview' => $prepared['preview'],
            // Optional upload spec ({required, label, multiple}): presence makes the confirm card render a file field.
            'upload' => $prepared['upload'] ?? null,
            'summary' => $prepared['summary'],
            'confirmation' => ($handler instanceof ProposableAction ? $handler->confirmation() : ConfirmationMode::Inline)->value,
            'status' => PendingAction::STATUS_PROPOSED,
            'expires_at' => now()->addMinutes((int) config('sidekick.actions_expire_after', 15)),
        ]);

        return [
            'action_id' => $action->id,
            'summary' => $action->summary,
            'preview' => $action->preview,
        ];
    }
}
