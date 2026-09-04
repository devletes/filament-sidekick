<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\ProposableAction;
use Devletes\Sidekick\Enums\ConfirmationMode;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/** Base for confirmable write actions, auto-offered as a Propose{ClassName} tool; throw \InvalidArgumentException with user-readable messages from prepare()/execute(). */
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

    public function confirmation(): ConfirmationMode
    {
        return ConfirmationMode::Inline;
    }

    public function instructions(): ?string
    {
        return null;
    }

    public function panels(): array
    {
        return ['*'];
    }

    /** Name the resources and models this action writes to, and deleting one becomes a reported problem instead of a surprise. */
    public function dependsOn(): array
    {
        return [];
    }
}
