<?php

namespace Devletes\Sidekick\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'sidekick:install')]
class InstallCommand extends Command
{
    protected $signature = 'sidekick:install {--migrate : Run the migrations after publishing}';

    protected $description = 'Publish the Sidekick config and walk through the remaining setup';

    public function handle(): int
    {
        $this->components->info('Installing Filament Sidekick…');

        $this->callSilently('vendor:publish', ['--tag' => 'sidekick-config']);
        $this->components->task('Config published to config/sidekick.php', fn (): bool => true);

        if ($this->option('migrate') || ($this->input->isInteractive() && $this->confirm('Run the migrations now?', true))) {
            $this->call('migrate');
        } else {
            $this->components->warn('Migrations were not run — run `php artisan migrate` before using the panel.');
        }

        $this->components->info('Almost there — the remaining steps live in your code:');

        $this->components->bulletList([
            'Register the plugin on your panel: ->plugins([\Devletes\Sidekick\SidekickPlugin::make()])',
            'Configure a provider for laravel/ai (e.g. ANTHROPIC_API_KEY or OPENAI_API_KEY in .env)',
            'Run a queue worker — chat turns are queued jobs: php artisan queue:work',
            'Create your first tool:  php artisan sidekick:tool SearchProjects',
            'Create a confirmable action:  php artisan sidekick:action CreateTask',
            'Optional: enable broadcasting (SIDEKICK_BROADCASTING=true) for instant updates over Reverb/Pusher',
        ]);

        $this->components->info('Tools and actions in app/Sidekick/{Tools,Actions} are discovered automatically — no registration needed.');

        return self::SUCCESS;
    }
}
