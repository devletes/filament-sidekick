<?php

namespace Devletes\Sidekick\Support;

/**
 * One party's allowance. Every field is nullable and null means unlimited, so a partially configured set
 * constrains only what it names.
 */
final class Limits
{
    public function __construct(
        public readonly ?int $requestsPerDay = null,
        public readonly ?int $requestsPerMonth = null,
        public readonly ?int $tokensPerDay = null,
        public readonly ?int $tokensPerMonth = null,
    ) {}

    /** @param  array<string, mixed>  $limits */
    public static function fromArray(?array $limits): self
    {
        $limits ??= [];

        $value = static fn (string $key): ?int => isset($limits[$key]) && $limits[$key] !== ''
            ? max(0, (int) $limits[$key])
            : null;

        return new self(
            requestsPerDay: $value('requests_per_day'),
            requestsPerMonth: $value('requests_per_month'),
            tokensPerDay: $value('tokens_per_day'),
            tokensPerMonth: $value('tokens_per_month'),
        );
    }

    public static function unlimited(): self
    {
        return new self;
    }

    public function isUnlimited(): bool
    {
        return $this->requestsPerDay === null
            && $this->requestsPerMonth === null
            && $this->tokensPerDay === null
            && $this->tokensPerMonth === null;
    }

    /**
     * Hold this allowance under a ceiling. A tenant hands out allowances from its own, so it can be stricter
     * than the platform allows but never more generous — an unset ceiling stays unset, and an unset field
     * inherits the ceiling's.
     */
    public function clampTo(self $ceiling): self
    {
        $lower = static fn (?int $mine, ?int $limit): ?int => match (true) {
            $limit === null => $mine,
            $mine === null => $limit,
            default => min($mine, $limit),
        };

        return new self(
            requestsPerDay: $lower($this->requestsPerDay, $ceiling->requestsPerDay),
            requestsPerMonth: $lower($this->requestsPerMonth, $ceiling->requestsPerMonth),
            tokensPerDay: $lower($this->tokensPerDay, $ceiling->tokensPerDay),
            tokensPerMonth: $lower($this->tokensPerMonth, $ceiling->tokensPerMonth),
        );
    }

    /** @return array<string, int|null> */
    public function toArray(): array
    {
        return [
            'requests_per_day' => $this->requestsPerDay,
            'requests_per_month' => $this->requestsPerMonth,
            'tokens_per_day' => $this->tokensPerDay,
            'tokens_per_month' => $this->tokensPerMonth,
        ];
    }
}
