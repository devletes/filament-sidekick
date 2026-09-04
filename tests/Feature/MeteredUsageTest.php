<?php

use Devletes\Sidekick\Contracts\LimitProvider;
use Devletes\Sidekick\Contracts\UsageLimiter;
use Devletes\Sidekick\Models\Run;
use Devletes\Sidekick\Support\Limits;
use Devletes\Sidekick\Tests\Fixtures\FakeUser;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->artisan('migrate');
    config()->set('sidekick.limits.enabled', true);
});

/** A finished run for a user in a tenant, costing $tokens. */
function meterRun(int $userId = 1, ?string $tenant = null, int $tokens = 0, bool $denied = false, ?string $at = null): Run
{
    return Run::query()->create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => (string) Str::uuid7(),
        'user_id' => $userId,
        'tenant_id' => $tenant,
        'prompt' => 'hi',
        'status' => Run::STATUS_COMPLETED,
        'tokens' => $tokens,
        'denied' => $denied,
        'created_at' => $at ? now()->parse($at) : now(),
        'updated_at' => now(),
    ]);
}

function limiter(): UsageLimiter
{
    return app(UsageLimiter::class);
}

it('allows everything while limits are switched off', function () {
    config()->set('sidekick.limits.enabled', false);
    config()->set('sidekick.limits.user.requests_per_day', 1);

    meterRun(userId: 1);

    expect(limiter()->check(FakeUser::make(), null))->toBeNull();
});

it('denies a user who has used their daily requests', function () {
    config()->set('sidekick.limits.user.requests_per_day', 2);

    meterRun(userId: 1);
    expect(limiter()->check(FakeUser::make(), null))->toBeNull();

    meterRun(userId: 1);
    expect(limiter()->check(FakeUser::make(), null))->toContain('your assistant allowance for today');
});

it('denies on tokens as well as request count', function () {
    config()->set('sidekick.limits.user.tokens_per_day', 100);

    meterRun(userId: 1, tokens: 60);
    expect(limiter()->check(FakeUser::make(), null))->toBeNull();

    meterRun(userId: 1, tokens: 40);
    expect(limiter()->check(FakeUser::make(), null))->toContain('your assistant allowance');
});

it('never counts a denied run against the allowance', function () {
    config()->set('sidekick.limits.user.requests_per_day', 2);

    // Rejections would otherwise compound: being refused once would help refuse you again.
    meterRun(userId: 1, denied: true);
    meterRun(userId: 1, denied: true);

    expect(limiter()->check(FakeUser::make(), null))->toBeNull();
});

it('counts only the current window', function () {
    config()->set('sidekick.limits.user.requests_per_day', 1);

    meterRun(userId: 1, at: now()->subDays(2)->toDateTimeString());

    expect(limiter()->check(FakeUser::make(), null))->toBeNull();
});

it('keeps one user\'s usage off another\'s allowance', function () {
    config()->set('sidekick.limits.user.requests_per_day', 1);

    meterRun(userId: 99);

    expect(limiter()->check(FakeUser::make(), null))->toBeNull();
});

it('reports the tenant running out before the user does', function () {
    config()->set('sidekick.limits.tenant.requests_per_day', 2);
    config()->set('sidekick.limits.user.requests_per_day', 10);

    // Two different people, one shared tenant allowance.
    meterRun(userId: 1);
    meterRun(userId: 2);

    expect(limiter()->check(FakeUser::make(), null))->toContain('Your organisation has used');
});

it('clamps a tenant-set user allowance to the platform\'s tenant allowance', function () {
    // The platform sold this tenant 2 requests a day; the tenant tried to hand one user 50.
    app()->bind(LimitProvider::class, fn () => new class implements LimitProvider
    {
        public function forTenant(int|string|null $tenant): Limits
        {
            return new Limits(requestsPerDay: 2);
        }

        public function forUser($user, int|string|null $tenant): Limits
        {
            return new Limits(requestsPerDay: 50);
        }
    });

    meterRun(userId: 1);
    meterRun(userId: 1);

    // The tenant cap binds first, and the user cap could never have exceeded it anyway.
    expect(limiter()->check(FakeUser::make(), null))->toContain('Your organisation has used');
});

it('lets a tenant be stricter than the platform requires', function () {
    app()->bind(LimitProvider::class, fn () => new class implements LimitProvider
    {
        public function forTenant(int|string|null $tenant): Limits
        {
            return new Limits(requestsPerDay: 100);
        }

        public function forUser($user, int|string|null $tenant): Limits
        {
            return new Limits(requestsPerDay: 1);
        }
    });

    meterRun(userId: 1);

    expect(limiter()->check(FakeUser::make(), null))->toContain('You have used');
});

it('clamps field by field, inheriting the ceiling where the tenant set nothing', function () {
    $ceiling = new Limits(requestsPerDay: 10, tokensPerDay: 1000);

    $clamped = (new Limits(requestsPerDay: 50))->clampTo($ceiling);

    expect($clamped->requestsPerDay)->toBe(10)
        // Unset by the tenant, so the platform's own figure applies rather than staying unlimited.
        ->and($clamped->tokensPerDay)->toBe(1000)
        // Untouched by either: still unlimited.
        ->and($clamped->requestsPerMonth)->toBeNull();
});

it('treats a set of nulls as unlimited', function () {
    expect(Limits::fromArray([])->isUnlimited())->toBeTrue()
        ->and(Limits::fromArray(['requests_per_day' => null])->isUnlimited())->toBeTrue()
        ->and(Limits::fromArray(['requests_per_day' => 5])->isUnlimited())->toBeFalse();
});
