<?php

namespace Devletes\Sidekick\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Ai\Contracts\Tool;

interface ChatTool extends Tool
{
    /** Whether the given user may use this tool at all (unauthorized tools are never offered to the model). */
    public function authorize(Authenticatable $user): bool;

    /** Short present-continuous status line shown while the tool runs, e.g. "Checking your leave balance". */
    public function label(): string;

    /** Write tools return true so the panel can gate execution behind an explicit confirmation. */
    public function needsConfirmation(): bool;
}
