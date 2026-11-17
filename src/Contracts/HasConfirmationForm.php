<?php

namespace Devletes\Sidekick\Contracts;

use Devletes\Sidekick\Models\PendingAction;
use Filament\Schemas\Components\Component;
use Filament\Support\Enums\Width;

/** A confirm card carrying a Filament form, filled from the payload's `form` key and handed back to execute() in it. */
interface HasConfirmationForm
{
    /** @return array<int, Component> */
    public function confirmationForm(PendingAction $action): array;

    /** The modal's width when the card renders in one; ignored inline. */
    public function confirmationWidth(): Width|string;
}
