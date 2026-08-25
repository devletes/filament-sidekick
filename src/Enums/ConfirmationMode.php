<?php

namespace Devletes\Sidekick\Enums;

/** How an action's confirmation card is presented in the panel. */
enum ConfirmationMode: string
{
    /** In the composer's place, inside the panel. Best for a handful of preview rows. */
    case Inline = 'inline';

    /** In a non-dismissible modal over the page. Best for previews too tall or wide for the panel. */
    case Modal = 'modal';
}
