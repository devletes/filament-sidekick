<?php

use Devletes\Sidekick\Jobs\RunChatTurn;
use Devletes\Sidekick\Support\PanelContext;
use Devletes\Sidekick\Support\ToolRegistry;
use Devletes\Sidekick\Tests\Fixtures\FakeUser;
use Devletes\Sidekick\Tests\Fixtures\Models\Employee;
use Devletes\Sidekick\Tests\Fixtures\Tools\EchoTool;
use Devletes\Sidekick\Tests\Fixtures\Tools\PanelBoundTool;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('offers panel-bound tools only inside their panel', function () {
    registerFilamentPanels($this->app);
    config()->set('sidekick.tools', [PanelBoundTool::class, EchoTool::class]);

    // No panel context: only '*' tools.
    $offered = fn (): array => array_map(
        fn ($tool): string => $tool::class,
        app(ToolRegistry::class)->authorizedFor(FakeUser::make()),
    );

    expect($offered())->toBe([EchoTool::class]);

    Filament::setCurrentPanel('testing');
    expect($offered())->toContain(PanelBoundTool::class)
        ->and($offered())->toContain(EchoTool::class);

    Filament::setCurrentPanel('tenanted');
    expect($offered())->toBe([EchoTool::class]);
});

it('captures the serving panel into the run job payload', function () {
    registerFilamentPanels($this->app);
    Filament::setCurrentPanel('testing');

    $job = new RunChatTurn('run-x');

    expect($job->filamentContext['panel'])->toBe('testing')
        ->and($job->filamentContext['tenant'])->toBeNull()
        ->and($job->filamentContext)->toHaveKey('guard');
});

it('captures nulls when no Filament context is available', function () {
    expect(PanelContext::capture())->toBe(['panel' => null, 'tenant' => null, 'guard' => null]);
});

it('restores the panel and tenant inside the worker', function () {
    registerFilamentPanels($this->app);

    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });
    $tenant = Employee::query()->create(['name' => 'Acme']);

    PanelContext::apply('tenanted', $tenant->getKey());

    expect(Filament::getCurrentPanel()?->getId())->toBe('tenanted')
        ->and(Filament::getTenant()?->is($tenant))->toBeTrue();
});

it('fails loudly when the tenant cannot be restored', function () {
    registerFilamentPanels($this->app);

    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    PanelContext::apply('tenanted', 999);
})->throws(RuntimeException::class);

it('does nothing without a captured panel', function () {
    PanelContext::apply(null, null);

    expect(true)->toBeTrue();
});

it('clears a previous job\'s tenant instead of letting it bleed into the next turn', function () {
    registerFilamentPanels($this->app);

    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });
    $tenant = Employee::query()->create(['name' => 'Acme']);

    PanelContext::apply('tenanted', $tenant->getKey());
    expect(Filament::getTenant()?->is($tenant))->toBeTrue();

    // Next job on the same worker came from a panel with no tenancy.
    PanelContext::apply('testing', null);

    expect(Filament::getTenant())->toBeNull()
        ->and(Filament::getCurrentPanel()?->getId())->toBe('testing');

    // ...and one dispatched outside any panel clears the panel too.
    PanelContext::apply(null, null);

    expect(Filament::getTenant())->toBeNull()
        ->and(Filament::getCurrentPanel())->toBeNull();
});
