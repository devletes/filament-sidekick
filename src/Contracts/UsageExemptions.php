<?php

namespace Devletes\Sidekick\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Turns that are free: they run whatever the allowance says, and they are still logged, tokens and all, but
 * they are not counted against it. A product might give away its own onboarding, or a support session.
 *
 * Asked where the limiter checks a turn and again when the queued turn runs, with the panel and tenant
 * context live both times. The default exempts nothing.
 */
interface UsageExemptions
{
    public function exempt(Authenticatable $user, int|string|null $tenant): bool;
}
