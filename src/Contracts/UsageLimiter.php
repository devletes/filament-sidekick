<?php

namespace Devletes\Sidekick\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/** check() runs fail-fast in the panel and again authoritatively in the queued job. */
interface UsageLimiter
{
    /** Null = allowed. A string denies the turn and is shown to the user verbatim. */
    public function check(Authenticatable $user, ?string $conversationId): ?string;
}
