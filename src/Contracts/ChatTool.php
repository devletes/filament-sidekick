<?php

namespace Devletes\Sidekick\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Ai\Contracts\Tool;

/** A read tool the assistant can call (extend Support\ChatToolBase); writes belong in a ProposableAction. */
interface ChatTool extends Tool
{
    /** Queued turns have no auth() session, so this is the only reliable source of the acting user. */
    public function forUser(Authenticatable $user): static;

    /** Unauthorized tools are never offered to the model. */
    public function authorize(Authenticatable $user): bool;

    /** Short present-continuous status line shown while the tool runs, e.g. "Checking your leave balance". */
    public function label(): string;

    /** Standing system prompt guidance while this tool is offered; author-written text only — never interpolate user or record data. */
    public function instructions(): ?string;

    /**
     * ['*'] (default) = every panel; only '*' tools are offered outside panel context.
     *
     * @return array<int, string>
     */
    public function panels(): array;

    /**
     * A missing dependency withholds the tool instead of fataling mid-turn.
     *
     * @return array<int, class-string>
     */
    public function dependsOn(): array;
}
