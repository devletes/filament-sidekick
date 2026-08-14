<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\ProposableAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * Base class for confirmable write actions. Subclasses define description(),
 * schema(), prepare(), and execute(); everything else has conventions:
 * type() is the snake_cased class basename, and the action is automatically
 * offered to the model as a Propose{ClassName} tool (no separate ChatTool
 * needed).
 *
 * Throw \InvalidArgumentException from prepare()/execute() with user-readable
 * messages for any validation failure — the message reaches the model (on
 * prepare) or the confirm card (on execute) verbatim.
 */
abstract class SidekickAction implements ProposableAction
{
    public function type(): string
    {
        return Str::snake(class_basename(static::class));
    }

    public function authorize(Authenticatable $user): bool
    {
        return true;
    }

    public function label(): string
    {
        return 'Preparing: '.Str::headline(class_basename(static::class));
    }
}
