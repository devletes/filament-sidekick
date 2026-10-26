<?php

namespace Devletes\Sidekick\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/** Exempt turns run and are logged but not counted against the allowance. */
interface UsageExemptions
{
    public function exempt(Authenticatable $user, int|string|null $tenant): bool;
}
