<?php

namespace Devletes\Sidekick\Tests\Fixtures;

use Devletes\Sidekick\Tests\Fixtures\Models\Employee;
use Devletes\Sidekick\Tests\Fixtures\Resources\EmployeeResource;
use Filament\Panel;
use Filament\PanelProvider;

class TenantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('tenanted')
            ->path('tenanted')
            ->tenant(Employee::class)
            ->resources([
                EmployeeResource::class,
            ]);
    }
}
