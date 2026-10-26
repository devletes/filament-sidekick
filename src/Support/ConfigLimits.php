<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\LimitProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/** Same config allowances for everyone; bind your own LimitProvider for per-tenant numbers. */
class ConfigLimits implements LimitProvider
{
    public function forTenant(int|string|null $tenant): Limits
    {
        return Limits::fromArray(config('sidekick.limits.tenant'));
    }

    public function forUser(Authenticatable $user, int|string|null $tenant): Limits
    {
        return Limits::fromArray(config('sidekick.limits.user'));
    }
}
