<?php

use Devletes\Sidekick\Tests\Fixtures\TenantPanelProvider;
use Devletes\Sidekick\Tests\Fixtures\TestPanelProvider;
use Devletes\Sidekick\Tests\TestCase;
use Filament\FilamentServiceProvider;

uses(TestCase::class)->in(__DIR__);

// Panel providers must register before FilamentServiceProvider first resolves
// the PanelRegistry (panel registration rides its resolving hook).
function registerFilamentPanels($app): void
{
    $app->register(TestPanelProvider::class);
    $app->register(TenantPanelProvider::class);
    $app->register(FilamentServiceProvider::class);
}
