<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\UsageExemptions;
use Illuminate\Contracts\Auth\Authenticatable;

/** Every turn counts. */
class NoExemptions implements UsageExemptions
{
    public function exempt(Authenticatable $user, int|string|null $tenant): bool
    {
        return false;
    }
}
