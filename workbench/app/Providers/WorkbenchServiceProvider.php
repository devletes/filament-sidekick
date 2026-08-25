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
            'sidekick.discover.tools' => __DIR__.'/../Sidekick/Tools',
            'sidekick.discover.actions' => __DIR__.'/../Sidekick/Actions',
            'sidekick.assistant.name' => 'Sidekick',
            'sidekick.assistant.description' => 'Ask about your leave, or ask me to book time off for you.',
            'sidekick.stale_after' => 86400,
            'sidekick.panel.full_height' => true,
        ]);
    }
}
