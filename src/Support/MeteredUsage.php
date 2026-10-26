<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\LimitProvider;
use Devletes\Sidekick\Contracts\UsageExemptions;
use Devletes\Sidekick\Contracts\UsageLimiter;
use Devletes\Sidekick\Models\Run;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/** Denied and exempt runs are never counted, so rejections can't lock someone out. */
class MeteredUsage implements UsageLimiter
{
    public function __construct(
        protected LimitProvider $limits,
        protected UsageExemptions $exemptions,
    ) {}

    public function check(Authenticatable $user, ?string $conversationId): ?string
    {
        if (! config('sidekick.limits.enabled', false)) {
            return null;
        }

        $tenant = $this->tenantKey();

        if ($this->exemptions->exempt($user, $tenant)) {
            return null;
        }

        $tenantLimits = $this->limits->forTenant($tenant);
        $userLimits = $this->limits->forUser($user, $tenant)->clampTo($tenantLimits);

        // Tenant first: "the organisation is out" is more useful than "you are out" when both are true.
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

    protected function spent(): Builder
    {
        return Run::query()->where('denied', false)->where('metered', true);
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
