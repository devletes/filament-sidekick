<?php

namespace Devletes\Sidekick\Tests\Fixtures;

use Devletes\Sidekick\Contracts\UsageLimiter;
use Illuminate\Contracts\Auth\Authenticatable;

class FlagLimiter implements UsageLimiter
{
    public static ?string $denial = null;

    public function check(Authenticatable $user, ?string $conversationId): ?string
    {
        return static::$denial;
    }
}
