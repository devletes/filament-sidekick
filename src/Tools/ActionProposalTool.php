<?php

namespace Devletes\Sidekick\Tools;

use Devletes\Sidekick\Contracts\ProposableAction;
use Devletes\Sidekick\Support\ChatToolBase;
use Devletes\Sidekick\Support\PendingActions;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;

/** Wraps a ProposableAction as the model-facing tool that PROPOSES it; execution only ever happens from the user's Confirm click. */
class ActionProposalTool extends ChatToolBase
{
    public function __construct(protected ProposableAction $action) {}

    /** Model-facing tool name, e.g. CreateExpense → ProposeCreateExpense. */
    public function name(): string
    {
        return 'Propose'.Str::studly($this->action->type());
    }

    public function description(): string
    {
        return rtrim($this->action->description(), '. ')
            .'. This only PROPOSES the action: the user is shown a confirmation card in the panel and decides.'
            .' Never claim the action happened — after calling this, tell the user to review the card.';
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->action->schema($schema);
    }

    public function authorize(Authenticatable $user): bool
    {
        return $this->action->authorize($user);
    }

    public function label(): string
    {
        return $this->action->label();
    }

    public function instructions(): ?string
    {
        return $this->action->instructions();
    }

    public function panels(): array
    {
        return $this->action->panels();
    }

    public function dependsOn(): array
    {
        return $this->action->dependsOn();
    }

    public function handle(Request $request): string
    {
        if (! $this->user) {
            return $this->respond(['error' => 'No authenticated user for this action.']);
        }

        try {
            $proposed = app(PendingActions::class)->propose(
                $this->action->type(),
                $request->all(),
                $this->user,
            );
        } catch (InvalidArgumentException $e) {
            return $this->respond(['error' => $e->getMessage()]);
        }

        return $this->respond([
            'proposed' => true,
            'action_id' => $proposed['action_id'],
            'summary' => $proposed['summary'],
            'note' => 'Awaiting the user\'s confirmation in the panel. Do not claim it happened.',
        ]);
    }
}
