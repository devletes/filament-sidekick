<?php

namespace Devletes\Sidekick\Widgets;

use Devletes\Sidekick\Models\Run;
use Devletes\Sidekick\Support\Insights;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Usage split by tenant, for a panel that is not itself scoped to one — a platform console asking which
 * customers actually use the assistant. Hidden anywhere the split would be a single row.
 *
 * Subclass and override tenantColumn() to render tenants however you like, then list your subclass from
 * your own page's getHeaderWidgets().
 */
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

    /** Override to render a tenant as more than a name — an avatar and a link, say. */
    protected function tenantColumn(): Column
    {
        return TextColumn::make('tenant_id')
            ->label(__('sidekick::messages.insights.tenant'))
            ->state(fn (Run $record): string => Insights::tenantLabel($record->tenant_id))
            ->sortable();
    }

    /**
     * Aggregate per tenant for the current month. `tenant_id as id` gives each row the key Filament needs,
     * and coalescing keeps the tenantless bucket addressable rather than dropping it.
     *
     * The aggregate is wrapped in a subquery deliberately. Filament appends a primary-key tiebreaker to every
     * sort — `order by turns desc, sidekick_runs.id desc` — which MySQL's only_full_group_by rejects against a
     * grouped query, since `id` is neither aggregated nor grouped. Selecting *from* the aggregate leaves the
     * outer query with no GROUP BY, so `id` is just a column and the tiebreaker is legal. Aliasing the
     * subquery as the model's own table is what keeps that qualified `sidekick_runs.id` resolving.
     */
    protected function query()
    {
        return Run::query()->fromSub(Insights::perTenantQuery(), (new Run)->getTable());
    }
}
