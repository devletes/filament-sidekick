<?php

use Illuminate\Support\Facades\File;

afterEach(function () {
    File::deleteDirectory(app_path('Sidekick'));
});

it('generates a tool into the auto-discovered path', function () {
    $this->artisan('sidekick:tool', ['name' => 'SearchProjects'])->assertSuccessful();

    $path = app_path('Sidekick/Tools/SearchProjects.php');

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)->toContain('class SearchProjects extends ChatToolBase')
        ->and($contents)->toContain('namespace App\Sidekick\Tools;');

    exec('php -l '.escapeshellarg($path), $output, $status);
    expect($status)->toBe(0);
});

it('generates an action into the auto-discovered path', function () {
    $this->artisan('sidekick:action', ['name' => 'CreateTask'])->assertSuccessful();

    $path = app_path('Sidekick/Actions/CreateTask.php');

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)->toContain('class CreateTask extends SidekickAction')
        ->and($contents)->toContain('namespace App\Sidekick\Actions;')
        ->and($contents)->toContain('Propose'.'CreateTask');

    exec('php -l '.escapeshellarg($path), $output, $status);
    expect($status)->toBe(0);
});

it('refuses to overwrite an existing class without --force', function () {
    $this->artisan('sidekick:tool', ['name' => 'Dupe'])->assertSuccessful();

    $path = app_path('Sidekick/Tools/Dupe.php');
    File::put($path, '<?php // customized');

    $this->artisan('sidekick:tool', ['name' => 'Dupe']);
    expect(File::get($path))->toBe('<?php // customized');

    $this->artisan('sidekick:tool', ['name' => 'Dupe', '--force' => true])->assertSuccessful();
    expect(File::get($path))->toContain('class Dupe extends ChatToolBase');
});
