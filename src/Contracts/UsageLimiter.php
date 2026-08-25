<?php

namespace Devletes\Sidekick\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/** Host-app seam for usage limits; check() runs fail-fast in the panel and again authoritatively in the queued job, with panel + tenant context live at both points. */
interface UsageLimiter
{
    /** Null = allowed. A string denies the turn and is shown to the user verbatim. */
    public function check(Authenticatable $user, ?string $conversationId): ?string;
}
