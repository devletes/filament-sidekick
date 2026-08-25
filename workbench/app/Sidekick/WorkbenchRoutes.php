<?php

namespace Workbench\App\Sidekick;

use Devletes\Sidekick\Contracts\ActionResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Workbench\App\Filament\Resources\LeaveRequestResource;

class WorkbenchRoutes implements ActionResolver
{
    public function targets(): array
    {
        return ['dashboard', 'time_off'];
    }

    public function resolve(string $target, ?string $record, Authenticatable $user): ?string
    {
        return match ($target) {
            'dashboard' => url('/admin'),
            'time_off' => LeaveRequestResource::getUrl('index', panel: 'admin'),
            default => null,
        };
    }
}
