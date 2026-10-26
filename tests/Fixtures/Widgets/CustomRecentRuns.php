<?php

namespace Devletes\Sidekick\Tests\Fixtures\Widgets;

use Devletes\Sidekick\Widgets\RecentRuns;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;

class CustomRecentRuns extends RecentRuns
{
    protected function userColumn(): Column
    {
        return TextColumn::make('user_id')
            ->label('Employee')
            ->state(fn ($record): string => 'EMP-'.$record->user_id);
    }
}
