<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\LimitProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Default LimitProvider: the same allowances for everyone, from config. Enough for a single-tenant app or a
 * flat plan; bind your own LimitProvider once tenants need their own numbers.
 */
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
