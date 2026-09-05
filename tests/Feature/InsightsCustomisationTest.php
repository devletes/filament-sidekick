<?php

use Devletes\Sidekick\Models\Run;
use Devletes\Sidekick\Pages\SidekickInsights;
use Devletes\Sidekick\SidekickPlugin;
use Devletes\Sidekick\Support\Insights;
use Devletes\Sidekick\Tests\Fixtures\Models\Employee;
use Devletes\Sidekick\Tests\Fixtures\Models\Person;
use Devletes\Sidekick\Tests\Fixtures\Pages\NyraInsights;
use Devletes\Sidekick\Tests\Fixtures\Widgets\CustomRecentRuns;
use Devletes\Sidekick\Widgets\RecentRuns;
use Devletes\Sidekick\Widgets\TenantUsage;
use Devletes\Sidekick\Widgets\UsageOverview;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->artisan('migrate');
    Insights::forgetLabels();
});

function customRun(?string $tenant = null, int $userId = 1): Run
{
    return Run::query()->create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => (string) Str::uuid7(),
        'user_id' => $userId,
        'tenant_id' => $tenant,
        'prompt' => 'hello',
        'status' => Run::STATUS_COMPLETED,
        'tokens' => 10,
        'denied' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function onPanel(string $panel = 'testing'): void
{
    registerFilamentPanels(app());
    Filament::setCurrentPanel($panel);
}

/** Register a configured plugin on the panel being served, the way a panel provider would. */
function withPlugin(SidekickPlugin $plugin, string $panel = 'testing'): void
{
    registerFilamentPanels(app());
    Filament::getPanel($panel)->plugin($plugin);
    Filament::setCurrentPanel($panel);
}

it('ships sensible navigation defaults', function () {
    onPanel();

    expect(SidekickInsights::getNavigationLabel())->toBe('Assistant insights')
        ->and(SidekickInsights::getNavigationIcon())->toBe('heroicon-o-chart-bar')
        ->and(SidekickInsights::getNavigationGroup())->toBeNull()
        ->and(SidekickInsights::shouldRegisterNavigation())->toBeTrue()
        // Filament derives the slug from the class name; no override needed.
        ->and(SidekickInsights::getRoutePath(Filament::getPanel('testing')))->toBe('/sidekick-insights');
});

it('honours a subclass overriding navigation the ordinary Filament way', function () {
    onPanel();

    // Static properties, no package API. This is the whole customisation story.
    expect(NyraInsights::getNavigationLabel())->toBe('Nyra Insights')
        ->and(NyraInsights::getNavigationIcon())->toBe('heroicon-o-sparkles')
        ->and(NyraInsights::getNavigationSort())->toBe(42)
        ->and(NyraInsights::getNavigationGroup())->toBe('Reports')
        ->and(NyraInsights::getRoutePath(Filament::getPanel('testing')))->toBe('/nyra-usage')
        // Route kept, sidebar entry dropped.
        ->and(NyraInsights::shouldRegisterNavigation())->toBeFalse();
});

it('takes the page heading from the subclass label', function () {
    onPanel();

    expect((new SidekickInsights)->getTitle())->toBe('Assistant insights')
        ->and((new NyraInsights)->getTitle())->toBe('Nyra Insights');
});

it('lets a subclass choose its own widgets', function () {
    onPanel();

    $widgets = (fn () => $this->getHeaderWidgets())->call(new NyraInsights);

    expect($widgets)->toBe([UsageOverview::class, CustomRecentRuns::class])
        ->not->toContain(TenantUsage::class);
});

it('registers the subclass instead of the packaged page, not as well as', function () {
    config()->set('sidekick.insights.enabled', true);
    registerFilamentPanels(app());

    $panel = Filament::getPanel('testing');
    SidekickPlugin::make()->enableInsights()->insightsPage(NyraInsights::class)->register($panel);

    expect($panel->getPages())->toContain(NyraInsights::class)
        // Registering both would put two entries in the sidebar.
        ->not->toContain(SidekickInsights::class);
});

it('still gates a subclass through canAccess', function () {
    registerFilamentPanels(app());
    config()->set('sidekick.insights.enabled', false);

    // Access is inherited, so a subclass cannot accidentally open the page up.
    expect(NyraInsights::canAccess())->toBeFalse();
});

it('renders a host subclass that overrides just one column', function () {
    onPanel();
    customRun(userId: 7);

    // The short path: change the cell, inherit everything else.
    Livewire::test(CustomRecentRuns::class)
        ->assertOk()
        ->assertSee('Employee')
        ->assertSee('EMP-7');
});

it('finds the tenant model from the panel that declares tenancy, with no config', function () {
    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    // The console panel has no tenancy of its own; the workspace panel beside it declares Employee.
    onPanel();

    expect(config('sidekick.insights.tenant_model'))->toBeNull()
        ->and(Insights::tenantModel())->toBe(Employee::class);

    $acme = Employee::query()->create(['name' => 'Acme Ltd']);
    customRun(tenant: (string) $acme->getKey());
    Insights::forgetLabels();

    expect(Insights::tenantLabel((string) $acme->getKey()))->toBe('Acme Ltd');
});

it('lets config override the discovered tenant model', function () {
    onPanel();
    config()->set('sidekick.insights.tenant_model', Employee::class);

    expect(Insights::tenantModel())->toBe(Employee::class);
});

it('falls back to the id when the label attribute does not exist', function () {
    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
    });

    onPanel();
    $acme = Employee::query()->create([]);
    Insights::forgetLabels();

    // A missing column degrades to the id rather than blanking the page.
    expect(Insights::tenantLabel((string) $acme->getKey()))->toBe((string) $acme->getKey());
});

it('finds the user model from the panel guard, with no config', function () {
    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    onPanel();
    // The guard's provider model is the user model; nothing about Sidekick needs restating.
    config()->set('auth.providers.users.model', Employee::class);

    $person = Employee::query()->create(['name' => 'Aria Whitfield']);
    customRun(userId: (int) $person->getKey());
    Insights::forgetLabels();

    expect(config('sidekick.insights.user_model'))->toBeNull()
        ->and(Insights::userLabel((string) $person->getKey()))->toBe('Aria Whitfield');
});

it('resolves a name that is an accessor rather than a column', function () {
    Schema::create('people', function (Blueprint $table) {
        $table->id();
        $table->string('first_name');
        $table->string('last_name');
        $table->timestamps();
    });

    onPanel();
    config()->set('sidekick.insights.user_model', Person::class);

    $person = Person::query()->create(['first_name' => 'Aria', 'last_name' => 'Whitfield']);
    customRun(userId: (int) $person->getKey());
    Insights::forgetLabels();

    // `select name` would fail here — there is no such column, only getNameAttribute().
    expect(Insights::userLabel((string) $person->getKey()))->toBe('Aria Whitfield');

    Insights::forgetLabels();
    Livewire::test(RecentRuns::class)->assertOk()->assertSee('Aria Whitfield');
});

it('resolves a page of rows in one query rather than one per row', function () {
    Schema::create('people', function (Blueprint $table) {
        $table->id();
        $table->string('first_name');
        $table->string('last_name');
        $table->timestamps();
    });

    onPanel();
    config()->set('sidekick.insights.user_model', Person::class);

    foreach (range(1, 5) as $i) {
        $person = Person::query()->create(['first_name' => "Person{$i}", 'last_name' => 'X']);
        customRun(userId: (int) $person->getKey());
    }

    Insights::forgetLabels();

    // Gathered before the listener, so only the label lookups are counted.
    $ids = Person::query()->pluck('id')->all();

    $queries = 0;
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'people')) {
            $queries++;
        }
    });

    Insights::primeUserLabels($ids);
    collect($ids)->each(fn ($id) => Insights::userLabel($id));

    // One batch for five people, and the per-id calls afterwards are all cache hits.
    expect($queries)->toBe(1);
});

it('resolves user ids to names out of the box', function () {
    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    onPanel();
    config()->set('sidekick.insights.user_model', Employee::class);

    $person = Employee::query()->create(['name' => 'Aria Whitfield']);
    customRun(userId: (int) $person->getKey());
    Insights::forgetLabels();

    Livewire::test(RecentRuns::class)->assertOk()->assertSee('Aria Whitfield');
});

it('shows the tenant name rather than the id in the per-tenant table', function () {
    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    onPanel();

    $acme = Employee::query()->create(['name' => 'Acme Ltd']);
    customRun(tenant: (string) $acme->getKey());

    config()->set('sidekick.insights.tenant_model', Employee::class);
    Insights::forgetLabels();

    Livewire::test(TenantUsage::class)
        ->assertOk()
        ->assertSee('Acme Ltd')
        ->assertDontSee('Usage by tenant, this month'.$acme->getKey());
});

it('keeps the aggregate in a subquery so the outer sort has no GROUP BY to fight', function () {
    onPanel();

    $query = (fn () => $this->query())->call(new TenantUsage);
    $sql = $query->toSql();

    // Filament appends `order by <sort>, sidekick_runs.id` to every sort. Against a grouped query MySQL's
    // only_full_group_by rejects that, so the grouping has to live one level down.
    expect($sql)->toMatch('/^select \* from \(select .* group by .*\) as /i')
        // Exactly one, and it belongs to the subquery — the outer select must stay ungrouped.
        ->and(substr_count(strtolower($sql), 'group by'))->toBe(1)
        ->and(Str::afterLast($sql, ') as '))->not->toContain('group by');
});

it('survives the primary-key tiebreaker Filament adds to sorts', function () {
    onPanel();

    customRun(tenant: 'acme');
    customRun(tenant: 'acme');
    customRun(tenant: 'globex');

    $rows = (fn () => $this->query())->call(new TenantUsage)
        ->orderBy('turns', 'desc')
        ->orderBy('sidekick_runs.id', 'desc')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->first()->tenant_id)->toBe('acme')
        ->and((int) $rows->first()->turns)->toBe(2);
});

it('still groups and aggregates correctly as a filament table', function () {
    onPanel();

    customRun(tenant: 'acme', userId: 1);
    customRun(tenant: 'acme', userId: 2);
    customRun(tenant: 'globex', userId: 3);

    Insights::forgetLabels();

    Livewire::test(TenantUsage::class)
        ->assertOk()
        ->assertSee('acme')
        ->assertSee('globex')
        ->assertSee('Usage by tenant');
});
