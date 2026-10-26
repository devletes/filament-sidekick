<?php

namespace Workbench\App\Filament\Resources;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Workbench\App\Filament\Resources\OrderResource\Pages\ListOrders;
use Workbench\App\Models\Order;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('placed_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('Order')->formatStateUsing(fn (int $state): string => "#{$state}")->searchable(),
                TextColumn::make('customer')->searchable(),
                TextColumn::make('items')->numeric(),
                TextColumn::make('total')->money('USD'),
                TextColumn::make('placed_at')->label('Placed')->date('M j'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'paid' => 'info',
                    'shipped' => 'success',
                    'refunded', 'cancelled' => 'gray',
                    default => 'warning',
                }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
        ];
    }
}
