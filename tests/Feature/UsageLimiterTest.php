<?php

use Devletes\Sidekick\Contracts\UsageLimiter;
use Devletes\Sidekick\Jobs\RunChatTurn;
use Devletes\Sidekick\Models\Run;
use Devletes\Sidekick\Support\MeteredUsage;
use Devletes\Sidekick\Support\UnlimitedUsage;
use Devletes\Sidekick\Tests\Fixtures\FakeUser;
use Devletes\Sidekick\Tests\Fixtures\FlagLimiter;
use Devletes\Sidekick\Tests\Fixtures\Models\Employee;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    FlagLimiter::$denial = null;
});

it('defaults to the metered limiter, which allows everything until limits are enabled', function () {
    $limiter = app(UsageLimiter::class);

    expect($limiter)->toBeInstanceOf(MeteredUsage::class)
        ->and($limiter->check(FakeUser::make(), null))->toBeNull();
});

it('still resolves UnlimitedUsage for hosts that ask for it by name', function () {
    config()->set('sidekick.usage_limiter', UnlimitedUsage::class);

    expect(app(UsageLimiter::class))->toBeInstanceOf(UnlimitedUsage::class);
});

it('resolves the limiter configured under sidekick.usage_limiter', function () {
    config()->set('sidekick.usage_limiter', FlagLimiter::class);

    expect(app(UsageLimiter::class))->toBeInstanceOf(FlagLimiter::class);
});

it('fails a queued turn with the limiter denial before spending tokens', function () {
    $this->artisan('migrate');

    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });
    $employee = Employee::query()->create(['name' => 'Jane']);

    config()->set('auth.providers.users.model', Employee::class);
    config()->set('sidekick.usage_limiter', FlagLimiter::class);
    FlagLimiter::$denial = 'Daily assistant limit reached — try again tomorrow.';

    $run = Run::query()->create([
        'conversation_id' => (string) Str::uuid(),
        'user_id' => $employee->id,
        'prompt' => 'hello',
        'status' => Run::STATUS_QUEUED,
    ]);

    (new RunChatTurn($run->id, ['panel' => null, 'tenant' => null]))->handle();

    $run->refresh();

    expect($run->status)->toBe(Run::STATUS_FAILED)
        ->and($run->error)->toBe('Daily assistant limit reached — try again tomorrow.')
        ->and($run->denied)->toBeTrue();
});
