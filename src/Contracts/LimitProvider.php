<?php

namespace Devletes\Sidekick\Contracts;

use Devletes\Sidekick\Support\Limits;
use Illuminate\Contracts\Auth\Authenticatable;

/** Allowance source: the platform caps each tenant, and the tenant divides its cap among its users. */
interface LimitProvider
{
    /** A null tenant means a single-tenant install. */
    public function forTenant(int|string|null $tenant): Limits;

    /** Clamped to forTenant() before it is enforced. */
    public function forUser(Authenticatable $user, int|string|null $tenant): Limits;
}
