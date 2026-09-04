<?php

namespace Devletes\Sidekick\Contracts;

use Devletes\Sidekick\Support\Limits;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Where allowances come from. Two levels, because that is how a multi-tenant product actually sells:
 * the platform caps each tenant, and the tenant divides its own cap among its people.
 *
 * The default reads both from config. Bind your own to read the tenant's plan and its per-user settings from
 * your tables — a user allowance is always clamped to the tenant's, so a tenant admin can be stricter than
 * their plan but never buy themselves more of it.
 */
interface LimitProvider
{
    /** The platform's cap on a whole tenant. A null tenant means a single-tenant install. */
    public function forTenant(int|string|null $tenant): Limits;

    /** The cap on one person, as their tenant set it. Clamped to forTenant() before it is enforced. */
    public function forUser(Authenticatable $user, int|string|null $tenant): Limits;
}
