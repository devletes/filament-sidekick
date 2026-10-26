<?php

namespace Devletes\Sidekick\Support;

/** Deliberately rough estimate, only for bounding rehydrated history; budget with headroom. */
class TokenBudget
{
    public static function estimate(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        // Bytes, not chars: tracks real tokenisers better across scripts (CJK is 3 bytes, ~1 token).
        return (int) ceil(strlen($text) / static::bytesPerToken());
    }

    protected static function bytesPerToken(): int
    {
        return max(1, (int) config('sidekick.history_bytes_per_token', 4));
    }
}
