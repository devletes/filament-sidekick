<?php

namespace Devletes\Sidekick\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Ai\Contracts\Tool;

/**
 * A read tool the panel's assistant can call. On top of laravel/ai's Tool
 * (description/schema/handle) Sidekick asks for per-user authorization and a
 * human status line. Extend Support\ChatToolBase for sensible defaults.
 *
 * Write operations don't belong here — implement a ProposableAction instead,
 * which gates execution behind the user's explicit confirmation.
 */
interface ChatTool extends Tool
{
    /** Whether the given user may use this tool at all (unauthorized tools are never offered to the model). */
    public function authorize(Authenticatable $user): bool;

    /** Short present-continuous status line shown while the tool runs, e.g. "Checking your leave balance". */
    public function label(): string;
}
