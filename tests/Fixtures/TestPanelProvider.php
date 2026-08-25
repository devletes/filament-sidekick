<?php

namespace Devletes\Sidekick\Tests\Fixtures;

use Devletes\Sidekick\Tests\Fixtures\Resources\EmployeeResource;
use Filament\Panel;
use Filament\PanelProvider;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('testing')
            ->path('testing')
            ->resources([
                EmployeeResource::class,
            ]);
    }
}
