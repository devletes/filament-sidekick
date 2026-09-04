<?php

namespace Devletes\Sidekick\Support;

/**
 * Rough token accounting for prompt sizing. There is no tokeniser in PHP that matches every provider, and
 * downloading one per model would cost more than it saves here — this is a deliberate estimate, used only to
 * bound how much history is rehydrated. Budget with headroom rather than to the exact context window.
 */
class TokenBudget
{
    public static function estimate(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        // Byte length, not character count: it tracks real tokenisers better across scripts, where a Latin
        // character is one byte but a CJK character is three — and also roughly one token.
        return (int) ceil(strlen($text) / static::bytesPerToken());
    }

    protected static function bytesPerToken(): int
    {
        return max(1, (int) config('sidekick.history_bytes_per_token', 4));
    }
}
