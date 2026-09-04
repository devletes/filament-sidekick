<?php

namespace Devletes\Sidekick\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Ai\Contracts\Tool;

/** A read tool the panel's assistant can call (extend Support\ChatToolBase for defaults); writes belong in a ProposableAction instead. */
interface ChatTool extends Tool
{
    /** Binds the acting user before handle() runs — queued turns have no auth() session, so this is the only reliable source. */
    public function forUser(Authenticatable $user): static;

    /** Whether the given user may use this tool at all (unauthorized tools are never offered to the model). */
    public function authorize(Authenticatable $user): bool;

    /** Short present-continuous status line shown while the tool runs, e.g. "Checking your leave balance". */
    public function label(): string;

    /** Standing system prompt guidance while this tool is offered; author-written text only — never interpolate user or record data. */
    public function instructions(): ?string;

    /**
     * Filament panel ids whose assistant offers this tool; ['*'] (default) = every panel, and only '*' tools are offered outside panel context.
     *
     * @return array<int, string>
     */
    public function panels(): array;

    /**
     * Classes this tool cannot work without — the resource it queries, the model it reads, a service it calls.
     * Delete one and the tool is withheld rather than fataling mid-turn, and `sidekick:check` names the file.
     *
     * @return array<int, class-string>
     */
    public function dependsOn(): array;
}
