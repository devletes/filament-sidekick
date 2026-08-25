<?php

namespace Devletes\Sidekick\Tests\Fixtures\Resources;

use Devletes\Sidekick\Tests\Fixtures\Models\Employee;
use Filament\Resources\Resource;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }

    public static function getRecordTitleAttribute(): ?string
    {
        return 'name';
    }

    public static function hasPage(string $page): bool
    {
        return in_array($page, ['index', 'view'], true);
    }
}
