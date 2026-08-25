<?php

namespace Workbench\App\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Workbench\App\Filament\Resources\LeaveRequestResource\Pages\ListLeaveRequests;
use Workbench\App\Models\LeaveRequest;

class LeaveRequestResource extends Resource
{
    protected static ?string $model = LeaveRequest::class;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $navigationLabel = 'Time off';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('user.name')->label('Employee')->searchable(),
            TextColumn::make('policy.name')->label('Policy'),
            TextColumn::make('from')->date('M j'),
            TextColumn::make('to')->date('M j'),
            TextColumn::make('days')->numeric(),
            TextColumn::make('status')->badge(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeaveRequests::route('/'),
        ];
    }
}
