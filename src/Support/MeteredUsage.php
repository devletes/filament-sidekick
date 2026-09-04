<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\LimitProvider;
use Devletes\Sidekick\Contracts\UsageLimiter;
use Devletes\Sidekick\Models\Run;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Config-driven UsageLimiter over the runs table. Enforces the tenant's allowance and then the user's, so
 * whichever runs out first is the one the person is told about.
 *
 * Counted against completed and in-flight runs only: a turn the limiter itself denied never spent anything,
 * so it must not eat into the allowance and lock someone out on the strength of their own rejections.
 */
class MeteredUsage implements UsageLimiter
{
    public function __construct(protected LimitProvider $limits) {}

    public function check(Authenticatable $user, ?string $conversationId): ?string
    {
        if (! config('sidekick.limits.enabled', false)) {
            return null;
        }

        $tenant = $this->tenantKey();

        $tenantLimits = $this->limits->forTenant($tenant);
        $userLimits = $this->limits->forUser($user, $tenant)->clampTo($tenantLimits);

        // The tenant is the wider bound, so it is reported first: "the organisation is out" is more useful
        // than "you are out" when both happen to be true.
        return $this->deny($tenantLimits, fn (): Builder => $this->scopedToTenant($tenant), 'tenant')
            ?? $this->deny($userLimits, fn (): Builder => $this->scopedToUser($user, $tenant), 'user');
    }

    /** @param  callable(): Builder  $scope */
    protected function deny(Limits $limits, callable $scope, string $party): ?string
    {
        if ($limits->isUnlimited()) {
            return null;
        }

        $windows = [
            ['requests', 'day', $limits->requestsPerDay],
            ['requests', 'month', $limits->requestsPerMonth],
            ['tokens', 'day', $limits->tokensPerDay],
            ['tokens', 'month', $limits->tokensPerMonth],
        ];

        foreach ($windows as [$metric, $window, $allowance]) {
            if ($allowance === null) {
                continue;
            }

            if ($this->used($scope(), $metric, $window) >= $allowance) {
                return __("sidekick::messages.limits.{$party}_{$metric}_{$window}");
            }
        }

        return null;
    }

    /** Requests are rows; tokens are the denormalised column, so neither decodes JSON. */
    protected function used(Builder $query, string $metric, string $window): int
    {
        $query->where('created_at', '>=', $window === 'day'
            ? now()->startOfDay()
            : now()->startOfMonth());

        return $metric === 'requests'
            ? (int) $query->count()
            : (int) $query->sum('tokens');
    }

    protected function scopedToTenant(int|string|null $tenant): Builder
    {
        return $this->spent()->where('tenant_id', $tenant === null ? null : (string) $tenant);
    }

    protected function scopedToUser(Authenticatable $user, int|string|null $tenant): Builder
    {
        return $this->scopedToTenant($tenant)->where('user_id', $user->getAuthIdentifier());
    }

    /** Runs that actually cost something: denied ones never reached a provider. */
    protected function spent(): Builder
    {
        return Run::query()->where('denied', false);
    }

    protected function tenantKey(): int|string|null
    {
        try {
            return Filament::getTenant()?->getKey();
        } catch (Throwable) {
            return null;
        }
    }
}
