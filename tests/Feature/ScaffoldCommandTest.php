<?php

use Devletes\Sidekick\Contracts\ActionResolver;
use Devletes\Sidekick\Tests\Fixtures\FixedResolver;
use Devletes\Sidekick\Tests\Fixtures\Resources\EmployeeResource;
use Devletes\Sidekick\Tests\Fixtures\TestPanelProvider;
use Filament\FilamentServiceProvider;
use Filament\Support\SupportServiceProvider;
use Illuminate\Support\Facades\File;

afterEach(function () {
    File::deleteDirectory(app_path('Sidekick'));
});

it('scaffolds a search tool and a resolver from the configured resources', function () {
    config()->set('sidekick.scaffold.only', [EmployeeResource::class => 'testing']);

    $this->artisan('sidekick:scaffold')->assertSuccessful();

    $tool = app_path('Sidekick/Tools/SearchEmployees.php');
    $resolver = app_path('Sidekick/ResourceResolver.php');

    expect(File::exists($tool))->toBeTrue()
        ->and(File::exists($resolver))->toBeTrue();

    $contents = File::get($tool);

    expect($contents)->toContain('class SearchEmployees extends ChatToolBase')
        ->and($contents)->toContain("['name', 'email']")
        ->and($contents)->toContain('EmployeeResource::getEloquentQuery()')
        ->and($contents)->toContain('$record->name')
        ->and($contents)->toContain("return ['testing'];");

    $resolverContents = File::get($resolver);

    expect($resolverContents)->toContain("'employees',")
        ->and($resolverContents)->toContain("'employees.record'")
        ->and($resolverContents)->toContain("getUrl('view', ['record' => \$record], panel: 'testing')");

    foreach ([$tool, $resolver] as $path) {
        exec('php -l '.escapeshellarg($path), $output, $status);
        expect($status)->toBe(0);
    }
});

it('enumerates resources from the registered Filament panels when no allowlist is set', function () {
    // Panel registration is deferred onto PanelRegistry's resolving hook, so
    // the panel provider must register before FilamentServiceProvider first
    // resolves the registry.
    $this->app->register(SupportServiceProvider::class);
    $this->app->register(TestPanelProvider::class);
    $this->app->register(FilamentServiceProvider::class);

    $this->artisan('sidekick:scaffold')->assertSuccessful();

    expect(File::exists(app_path('Sidekick/Tools/SearchEmployees.php')))->toBeTrue()
        ->and(File::get(app_path('Sidekick/ResourceResolver.php')))->toContain("panel: 'testing'");
});

it('generates tenant-aware urls and panel scoping for tenanted panels', function () {
    registerFilamentPanels($this->app);
    config()->set('sidekick.scaffold.only', [EmployeeResource::class => 'tenanted']);

    $this->artisan('sidekick:scaffold')->assertSuccessful();

    expect(File::get(app_path('Sidekick/ResourceResolver.php')))
        ->toContain("tenant: \Filament\Facades\Filament::getTenant()")
        ->and(File::get(app_path('Sidekick/Tools/SearchEmployees.php')))
        ->toContain("return ['tenanted'];");
});

it('scaffolds actions only when asked, as throwing stubs', function () {
    config()->set('sidekick.scaffold.only', [EmployeeResource::class]);

    $this->artisan('sidekick:scaffold')->assertSuccessful();
    expect(File::exists(app_path('Sidekick/Actions/CreateEmployee.php')))->toBeFalse();

    $this->artisan('sidekick:scaffold', ['--actions' => true])->assertSuccessful();

    $contents = File::get(app_path('Sidekick/Actions/CreateEmployee.php'));

    expect($contents)->toContain('class CreateEmployee extends SidekickAction')
        ->and($contents)->toContain('execute() is not implemented yet');

    exec('php -l '.escapeshellarg(app_path('Sidekick/Actions/CreateEmployee.php')), $output, $status);
    expect($status)->toBe(0);
});

it('honours the ignore list', function () {
    config()->set('sidekick.scaffold.only', [EmployeeResource::class]);
    config()->set('sidekick.scaffold.ignore', [EmployeeResource::class]);

    $this->artisan('sidekick:scaffold')->assertSuccessful();

    expect(File::exists(app_path('Sidekick/Tools/SearchEmployees.php')))->toBeFalse();
});

it('never overwrites an existing scaffold on re-runs', function () {
    config()->set('sidekick.scaffold.only', [EmployeeResource::class]);

    File::ensureDirectoryExists(app_path('Sidekick/Tools'));
    File::put(app_path('Sidekick/Tools/SearchEmployees.php'), '<?php // customized');

    $this->artisan('sidekick:scaffold')->assertSuccessful();

    expect(File::get(app_path('Sidekick/Tools/SearchEmployees.php')))->toBe('<?php // customized');
});

it('writes nothing on a dry run', function () {
    config()->set('sidekick.scaffold.only', [EmployeeResource::class]);

    $this->artisan('sidekick:scaffold', ['--dry-run' => true])->assertSuccessful();

    expect(File::exists(app_path('Sidekick')))->toBeFalse();
});

it('binds the resolver class configured under sidekick.action_resolver', function () {
    config()->set('sidekick.action_resolver', FixedResolver::class);

    expect(app(ActionResolver::class))->toBeInstanceOf(FixedResolver::class);
});
