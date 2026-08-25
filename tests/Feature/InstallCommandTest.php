<?php

use Illuminate\Support\Facades\File;

it('publishes the config and reports next steps', function () {
    File::delete(config_path('sidekick.php'));

    $this->artisan('sidekick:install', ['--migrate' => true])
        ->expectsOutputToContain('config/sidekick.php')
        ->assertSuccessful();

    expect(File::exists(config_path('sidekick.php')))->toBeTrue();

    File::delete(config_path('sidekick.php'));
});
