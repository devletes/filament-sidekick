<?php

namespace Devletes\Sidekick\Enums;

enum ConfirmationMode: string
{
    /** Best for a handful of preview rows. */
    case Inline = 'inline';

    /** Non-dismissible; best for previews too tall or wide for the panel. */
    case Modal = 'modal';
}
