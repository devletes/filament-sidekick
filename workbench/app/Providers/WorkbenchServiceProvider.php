<?php

namespace Workbench\App\Providers;

use Devletes\Sidekick\Contracts\ActionResolver;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Sidekick\WorkbenchRoutes;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActionResolver::class, WorkbenchRoutes::class);
    }

    public function boot(): void
    {
        // testbench's app_path() is the skeleton's, so point discovery at the workbench.
        config([
            'sidekick.discover.paths' => __DIR__.'/../Sidekick',
            // History and insights are enabled in AdminPanelProvider: pages register before this boots.
            'sidekick.assistant.name' => 'Sidekick',
            'sidekick.assistant.description' => 'Ask about orders and sales, or ask me to update an order.',
            'sidekick.stale_after' => 86400,
            'sidekick.panel.full_height' => true,
        ]);
    }
}
