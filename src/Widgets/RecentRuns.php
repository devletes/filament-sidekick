<?php

namespace Devletes\Sidekick\Widgets;

use Devletes\Sidekick\Models\Run;
use Devletes\Sidekick\Support\Insights;
use Devletes\Sidekick\Support\ToolRegistry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentRuns extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('sidekick::messages.insights.recent_heading'))
            ->query(fn () => Insights::runs()->latest())
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50])
            ->columns(array_values(array_filter([
                TextColumn::make('created_at')
                    ->label(__('sidekick::messages.insights.when'))
                    ->since()
                    ->tooltip(fn (Run $run): string => (string) $run->created_at),

                TextColumn::make('user_id')
                    ->label(__('sidekick::messages.insights.user')),

                // Off by default: a prompt is the person's own words, and an operator dashboard is not
                // automatically the right place to read them.
                config('sidekick.insights.show_prompts', false)
                    ? TextColumn::make('prompt')
                        ->label(__('sidekick::messages.insights.prompt'))
                        ->limit(60)
                        ->wrap()
                    : null,

                TextColumn::make('status')
                    ->label(__('sidekick::messages.insights.status'))
                    ->badge()
                    ->formatStateUsing(fn (Run $run): string => $run->denied
                        ? __('sidekick::messages.insights.denied')
                        : $run->status)
                    ->color(fn (Run $run): string => match (true) {
                        $run->denied => 'warning',
                        $run->status === Run::STATUS_COMPLETED => 'success',
                        $run->status === Run::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('tools')
                    ->label(__('sidekick::messages.insights.tools'))
                    ->state(fn (Run $run): string => $this->toolsUsed($run))
                    ->wrap(),

                TextColumn::make('tokens')
                    ->label(__('sidekick::messages.insights.tokens'))
                    ->numeric()
                    ->alignEnd(),
            ])));
    }

    /** The tools a run actually called, by their human labels rather than class names. */
    protected function toolsUsed(Run $run): string
    {
        $registry = app(ToolRegistry::class);

        $names = collect($run->activity ?? [])
            ->filter(fn ($entry): bool => ($entry['type'] ?? null) === 'call')
            ->map(fn ($entry): string => (string) ($entry['name'] ?? ''))
            ->filter()
            ->unique()
            ->map(fn (string $name): string => $registry->labelFor($name) ?? $name);

        return $names->isEmpty() ? '—' : $names->join(', ');
    }
}
