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
        // testbench's app_path() is the skeleton's, so point discovery at the workbench. One root is enough:
        // the scan recurses into Tools/ and Actions/, and skips WorkbenchRoutes at the top (it is a resolver).
        config([
            'sidekick.discover.paths' => __DIR__.'/../Sidekick',
            // History and insights are switched on through the plugin API in AdminPanelProvider — page
            // registration happens before this provider boots, so config set here would be too late.
            'sidekick.assistant.name' => 'Sidekick',
            'sidekick.assistant.description' => 'Ask about your leave, or ask me to book time off for you.',
            'sidekick.stale_after' => 86400,
            'sidekick.panel.full_height' => true,
        ]);
    }
}
