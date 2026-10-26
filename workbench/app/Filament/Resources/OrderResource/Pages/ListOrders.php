<?php

namespace Workbench\App\Filament\Resources\OrderResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Workbench\App\Filament\Resources\OrderResource;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;
}
