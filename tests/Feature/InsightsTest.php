<?php

use Devletes\Sidekick\Models\Run;
use Devletes\Sidekick\Pages\SidekickInsights;
use Devletes\Sidekick\SidekickPlugin;
use Devletes\Sidekick\Support\Insights;
use Devletes\Sidekick\Tests\Fixtures\FakeUser;
use Devletes\Sidekick\Tests\Fixtures\Models\Employee;
use Devletes\Sidekick\Widgets\RecentRuns;
use Devletes\Sidekick\Widgets\TenantUsage;
use Devletes\Sidekick\Widgets\TurnsChart;
use Devletes\Sidekick\Widgets\UsageOverview;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->artisan('migrate');
});

/** Widgets resolve Filament's panel container, so they need a panel in context. */
function inPanel(): void
{
    registerFilamentPanels(app());
    Filament::setCurrentPanel('testing');
}

function insightRun(?string $tenant = null, int $tokens = 100, string $status = Run::STATUS_COMPLETED, bool $denied = false, ?string $at = null): Run
{
    return Run::query()->create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => (string) Str::uuid7(),
        'user_id' => 1,
        'tenant_id' => $tenant,
        'prompt' => 'hello',
        'status' => $status,
        'tokens' => $tokens,
        'denied' => $denied,
        'activity' => [['type' => 'call', 'name' => 'EchoTool']],
        'created_at' => $at ? now()->parse($at) : now(),
        'updated_at' => now(),
    ]);
}

it('shows every run when the panel has no tenancy', function () {
    registerFilamentPanels($this->app);
    Filament::setCurrentPanel('testing');

    insightRun(tenant: null);
    insightRun(tenant: '7');

    expect(Insights::runs()->count())->toBe(2);
});

it('shows only the current tenant\'s runs on a tenant panel', function () {
    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    registerFilamentPanels($this->app);
    Filament::setCurrentPanel('tenanted');

    $tenant = Employee::query()->create(['name' => 'Acme']);
    Filament::setTenant($tenant, isQuiet: true);

    insightRun(tenant: (string) $tenant->getKey());
    insightRun(tenant: '999');
    insightRun(tenant: null);

    expect(Insights::runs()->count())->toBe(1);
});

it('shows nothing rather than everything when tenancy is on but no tenant resolved', function () {
    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    registerFilamentPanels($this->app);
    Filament::setCurrentPanel('tenanted');
    Filament::setTenant(null, isQuiet: true);

    insightRun(tenant: '1');
    insightRun(tenant: null);

    // Failing open here would leak every tenant's totals into one page.
    expect(Insights::runs()->count())->toBe(0);
});

it('leaves denied runs out of spend but keeps them in the record', function () {
    insightRun(denied: false, tokens: 100);
    insightRun(denied: true, tokens: 0);

    expect(Insights::runs()->count())->toBe(2)
        ->and(Insights::spent()->count())->toBe(1);
});

it('zero-fills the daily series so the chart has no gaps', function () {
    insightRun(tokens: 50);
    insightRun(tokens: 70, at: now()->subDays(3)->toDateTimeString());

    $daily = Insights::daily(7);

    expect($daily['labels'])->toHaveCount(7)
        ->and($daily['turns'])->toHaveCount(7)
        ->and(array_sum($daily['turns']))->toBe(2)
        ->and(array_sum($daily['tokens']))->toBe(120)
        // Today is the last bucket.
        ->and($daily['turns'][6])->toBe(1);
});

it('ignores runs older than the window', function () {
    insightRun(at: now()->subDays(40)->toDateTimeString());

    expect(array_sum(Insights::daily(30)['turns']))->toBe(0);
});

it('renders the stats widget with real totals', function () {
    inPanel();
    insightRun(tokens: 120);
    insightRun(tokens: 80, status: Run::STATUS_FAILED);

    Livewire::test(UsageOverview::class)
        ->assertOk()
        // Two turns today, 200 tokens between them.
        ->assertSee('200')
        ->assertSee('Turns today');
});

it('renders the chart and recent-runs widgets', function () {
    inPanel();
    insightRun();

    Livewire::test(TurnsChart::class)->assertOk();
    Livewire::test(RecentRuns::class)->assertOk()->assertSee('Recent turns');
});

it('keeps prompts out of the recent-runs table unless asked for', function () {
    inPanel();
    insightRun();
    Run::query()->update(['prompt' => 'my private question']);

    Livewire::test(RecentRuns::class)->assertDontSee('my private question');

    config()->set('sidekick.insights.show_prompts', true);

    Livewire::test(RecentRuns::class)->assertSee('my private question');
});

it('breaks usage down per tenant on a panel that spans them', function () {
    inPanel();

    insightRun(tenant: 'acme', tokens: 100);
    insightRun(tenant: 'acme', tokens: 50, status: Run::STATUS_FAILED);
    insightRun(tenant: 'globex', tokens: 300);
    insightRun(tenant: null, tokens: 10);

    $rows = Insights::perTenantQuery()->orderByDesc('turns')->get()->keyBy('tenant_id');

    expect($rows)->toHaveCount(3)
        ->and((int) $rows['acme']->turns)->toBe(2)
        ->and((int) $rows['acme']->tokens)->toBe(150)
        ->and((int) $rows['acme']->failed)->toBe(1)
        ->and((int) $rows['globex']->turns)->toBe(1)
        // Runs with no tenant are still someone's usage; they get their own bucket rather than being dropped.
        ->and((int) $rows['']->turns)->toBe(1);
});

it('counts distinct people per tenant rather than turns', function () {
    inPanel();

    insightRun(tenant: 'acme');
    insightRun(tenant: 'acme');

    $acme = Insights::perTenantQuery()->get()->firstWhere('tenant_id', 'acme');

    expect((int) $acme->people)->toBe(1)
        ->and((int) $acme->turns)->toBe(2);
});

it('labels the tenantless bucket rather than leaving it blank', function () {
    inPanel();
    insightRun(tenant: null);

    expect(Insights::tenantLabel(null))->toBe('No tenant');
});

it('names tenants when a tenant model is configured, and falls back to the id', function () {
    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    inPanel();

    $acme = Employee::query()->create(['name' => 'Acme Ltd']);
    insightRun(tenant: (string) $acme->getKey());
    insightRun(tenant: '4242');

    config()->set('sidekick.insights.tenant_model', Employee::class);
    Insights::forgetLabels();

    expect(Insights::tenantLabel((string) $acme->getKey()))->toBe('Acme Ltd')
        // Unknown id: shown as itself rather than blank.
        ->and(Insights::tenantLabel('4242'))->toBe('4242');
});

it('shows the per-tenant breakdown only on a cross-tenant panel of a multi-tenant install', function () {
    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    registerFilamentPanels(app());

    // A console beside a tenant panel: this is the one case worth breaking down.
    Filament::setCurrentPanel('testing');
    expect(Insights::isMultiTenant())->toBeTrue()
        ->and(TenantUsage::canView())->toBeTrue();

    // A tenant panel is already one tenant; the split would be a single row.
    Filament::setCurrentPanel('tenanted');
    expect(TenantUsage::canView())->toBeFalse();
});

it('never offers a per-tenant breakdown on a single-tenant install', function () {
    registerFilamentPanels(app());
    // Detection asks the panels; forcing it off stands in for an app with no tenant panel at all.
    config()->set('sidekick.tenancy.multi_tenant', false);
    Filament::setCurrentPanel('testing');

    // Otherwise every run has a null tenant and the breakdown is one meaningless row.
    expect(Insights::spansTenants())->toBeFalse()
        ->and(TenantUsage::canView())->toBeFalse();
});

it('lets config override tenancy detection in both directions', function () {
    registerFilamentPanels(app());
    Filament::setCurrentPanel('testing');

    config()->set('sidekick.tenancy.multi_tenant', true);
    expect(Insights::isMultiTenant())->toBeTrue();

    config()->set('sidekick.tenancy.multi_tenant', false);
    expect(Insights::isMultiTenant())->toBeFalse();

    // null falls back to detection, and this app does have a tenant panel.
    config()->set('sidekick.tenancy.multi_tenant', null);
    expect(Insights::isMultiTenant())->toBeTrue();
});

it('renders the per-tenant widget', function () {
    inPanel();
    insightRun(tenant: 'acme', tokens: 120);

    Livewire::test(TenantUsage::class)
        ->assertOk()
        ->assertSee('Usage by tenant')
        ->assertSee('acme');
});

it('refuses access while insights are switched off', function () {
    registerFilamentPanels($this->app);
    Filament::setCurrentPanel('testing');
    config()->set('sidekick.insights.enabled', false);

    expect(SidekickInsights::canAccess())->toBeFalse();
});

it('honours the authorization closure passed to enableInsights', function () {
    registerFilamentPanels($this->app);

    $panel = Filament::getPanel('testing');
    $panel->plugin(SidekickPlugin::make()->enableInsights(fn ($user): bool => false));
    Filament::setCurrentPanel('testing');

    config()->set('sidekick.insights.enabled', true);
    $this->actingAs(FakeUser::make());

    expect(SidekickInsights::canAccess())->toBeFalse();
});
