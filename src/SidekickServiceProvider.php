<?php

namespace Devletes\Sidekick;

use Devletes\Sidekick\Assets\HashedCss;
use Devletes\Sidekick\Assets\HashedJs;
use Devletes\Sidekick\Console\InstallCommand;
use Devletes\Sidekick\Console\MakeActionCommand;
use Devletes\Sidekick\Console\MakeToolCommand;
use Devletes\Sidekick\Console\PruneAttachments;
use Devletes\Sidekick\Console\ScaffoldCommand;
use Devletes\Sidekick\Contracts\ActionResolver;
use Devletes\Sidekick\Contracts\UsageLimiter;
use Devletes\Sidekick\Livewire\ChatPanel;
use Devletes\Sidekick\Storage\LeanConversationStore;
use Devletes\Sidekick\Support\DefaultSidekickContext;
use Devletes\Sidekick\Support\NullActionResolver;
use Devletes\Sidekick\Support\Profiles;
use Devletes\Sidekick\Support\SidekickContext;
use Devletes\Sidekick\Support\SidekickManager;
use Devletes\Sidekick\Support\UnlimitedUsage;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Contracts\ConversationStore;
use Livewire\Livewire;

class SidekickServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sidekick.php', 'sidekick');

        $this->app->singleton(SidekickManager::class);
        $this->app->singletonIf(SidekickContext::class, DefaultSidekickContext::class);
        $this->app->singletonIf(
            ActionResolver::class,
            fn ($app) => $app->make(
                config('sidekick.action_resolver') ?? NullActionResolver::class,
            ),
        );
        $this->app->singletonIf(
            UsageLimiter::class,
            fn ($app) => $app->make(
                config('sidekick.usage_limiter') ?? UnlimitedUsage::class,
            ),
        );
        $this->app->singleton(Profiles::class);
    }

    public function boot(): void
    {
        // Bound in boot (after all registers) so it overrides laravel/ai's own ConversationStore binding.
        if (config('sidekick.lean_history', true)) {
            $this->app->singleton(
                ConversationStore::class,
                LeanConversationStore::class,
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
                InstallCommand::class,
                MakeToolCommand::class,
                MakeActionCommand::class,
                ScaffoldCommand::class,
                PruneAttachments::class,
            ]);
        }

        FilamentAsset::register([
            HashedCss::make('sidekick', __DIR__.'/../resources/css/sidekick.css'),
            HashedJs::make('sidekick', __DIR__.'/../resources/js/sidekick.js'),
        ], package: 'devletes/filament-sidekick');

        Broadcast::channel('sidekick.user.{userId}', function ($user, string $userId): bool {
            return (string) $user->getAuthIdentifier() === $userId;
        });
    }
}
