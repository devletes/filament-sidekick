<?php

namespace Workbench\App\Sidekick;

use Devletes\Sidekick\Contracts\ActionResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Workbench\App\Filament\Resources\OrderResource;

class WorkbenchRoutes implements ActionResolver
{
    public function targets(): array
    {
        return ['dashboard', 'orders'];
    }

    public function resolve(string $target, ?string $record, Authenticatable $user): ?string
    {
        return match ($target) {
            'dashboard' => url('/admin'),
            'orders' => OrderResource::getUrl('index', panel: 'admin'),
            default => null,
        };
    }
}
