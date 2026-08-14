<?php

namespace Devletes\Sidekick;

use Devletes\Sidekick\Livewire\ChatPanel;
use Devletes\Sidekick\Support\SidekickContext;
use Devletes\Sidekick\Support\DefaultSidekickContext;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class SidekickServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sidekick.php', 'sidekick');

        $this->app->singleton(\Devletes\Sidekick\Support\SidekickManager::class);
        $this->app->singletonIf(SidekickContext::class, DefaultSidekickContext::class);
        $this->app->singletonIf(
            \Devletes\Sidekick\Contracts\ActionResolver::class,
            \Devletes\Sidekick\Support\NullActionResolver::class,
        );
        $this->app->singleton(\Devletes\Sidekick\Support\Profiles::class);
    }

    public function boot(): void
    {
        // In boot (after every register) so it deterministically overrides
        // laravel/ai's own ConversationStore binding.
        if (config('sidekick.lean_history', true)) {
            $this->app->singleton(
                \Laravel\Ai\Contracts\ConversationStore::class,
                \Devletes\Sidekick\Storage\LeanConversationStore::class,
            );
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'sidekick');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/sidekick.php' => config_path('sidekick.php'),
        ], 'sidekick-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/sidekick'),
        ], 'sidekick-views');

        Livewire::component('sidekick.chat-panel', ChatPanel::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Devletes\Sidekick\Console\InstallCommand::class,
                \Devletes\Sidekick\Console\MakeToolCommand::class,
                \Devletes\Sidekick\Console\MakeActionCommand::class,
                \Devletes\Sidekick\Console\PruneAttachments::class,
            ]);
        }

        FilamentAsset::register([
            \Devletes\Sidekick\Assets\HashedCss::make('sidekick', __DIR__.'/../resources/css/sidekick.css'),
            \Devletes\Sidekick\Assets\HashedJs::make('sidekick', __DIR__.'/../resources/js/sidekick.js'),
        ], package: 'devletes/filament-sidekick');

        Broadcast::channel('sidekick.user.{userId}', function ($user, string $userId): bool {
            return (string) $user->getAuthIdentifier() === $userId;
        });
    }
}
