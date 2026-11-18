<?php

use Devletes\Sidekick\Jobs\RunChatTurn;
use Devletes\Sidekick\Models\Run;
use Devletes\Sidekick\Tests\Fixtures\FlagLimiter;
use Devletes\Sidekick\Tests\Fixtures\Models\Employee;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('finishes a turn when the broadcast server cannot be reached', function () {
    $this->artisan('migrate');

    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });
    $employee = Employee::query()->create(['name' => 'Jane']);

    config()->set('auth.providers.users.model', Employee::class);
    config()->set('sidekick.usage_limiter', FlagLimiter::class);
    FlagLimiter::$denial = 'Limit reached.';

    Broadcast::extend('unreachable', fn () => new class extends Broadcaster
    {
        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new BroadcastException('Failed to connect');
        }
    });
    config()->set('broadcasting.connections.unreachable', ['driver' => 'unreachable']);
    config()->set('broadcasting.default', 'unreachable');
    config()->set('sidekick.broadcasting.enabled', true);

    $run = Run::query()->create([
        'conversation_id' => (string) Str::uuid(),
        'user_id' => $employee->id,
        'prompt' => 'hello',
        'status' => Run::STATUS_QUEUED,
    ]);

    (new RunChatTurn($run->id, ['panel' => null, 'tenant' => null]))->handle();

    expect($run->refresh()->status)->toBe(Run::STATUS_FAILED)
        ->and($run->error)->toBe('Limit reached.');
});
