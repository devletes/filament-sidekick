<?php

namespace Devletes\Sidekick\Tests\Fixtures\Tools;

/** Lives in the discovery path but implements nothing — must be skipped. */
class NotATool
{
    public function anything(): string
    {
        return 'nope';
    }
}
