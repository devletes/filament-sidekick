<?php

namespace Devletes\Sidekick\Widgets;

use Devletes\Sidekick\Models\Run;
use Devletes\Sidekick\Support\Insights;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Usage split by tenant; hidden wherever the split would be a single row. */
class TenantUsage extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Insights::spansTenants();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('sidekick::messages.insights.by_tenant'))
            ->query(fn () => $this->query())
            // One row per tenant is a short list; paginating it would only hide the tail.
            ->paginated(false)
            ->defaultSort('turns', 'desc')
            ->emptyStateHeading(__('sidekick::messages.insights.no_usage'))
            ->columns([
                $this->tenantColumn(),

                TextColumn::make('turns')
                    ->label(__('sidekick::messages.insights.turns'))
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('tokens')
                    ->label(__('sidekick::messages.insights.tokens'))
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('people')
                    ->label(__('sidekick::messages.insights.people'))
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('failed')
                    ->label(__('sidekick::messages.insights.failed'))
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->color(fn ($state): string => $state > 0 ? 'danger' : 'gray'),
            ]);
    }

    protected function tenantColumn(): Column
    {
        return TextColumn::make('tenant_id')
            ->label(__('sidekick::messages.insights.tenant'))
            ->state(fn (Run $record): string => Insights::tenantLabel($record->tenant_id))
            ->sortable();
    }

    /** Subquery aliased as the runs table so Filament's `sidekick_runs.id` sort tiebreaker survives only_full_group_by. */
    protected function query()
    {
        return Run::query()->fromSub(Insights::perTenantQuery(), (new Run)->getTable());
    }
}
