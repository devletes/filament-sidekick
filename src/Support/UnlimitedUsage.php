<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\UsageLimiter;
use Illuminate\Contracts\Auth\Authenticatable;

class UnlimitedUsage implements UsageLimiter
{
    public function check(Authenticatable $user, ?string $conversationId): ?string
    {
        return null;
    }
}
