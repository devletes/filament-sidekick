<?php

namespace Devletes\Sidekick\Widgets;

use Devletes\Sidekick\Models\Run;
use Devletes\Sidekick\Support\Insights;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UsageOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $today = Insights::spent()->where('created_at', '>=', now()->startOfDay());
        $month = Insights::spent()->where('created_at', '>=', now()->startOfMonth());

        $monthTurns = (clone $month)->count();
        $failed = (clone $month)->where('status', Run::STATUS_FAILED)->count();
        $denied = Insights::runs()
            ->where('denied', true)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        return [
            Stat::make(__('sidekick::messages.insights.turns_today'), number_format((clone $today)->count()))
                ->description(__('sidekick::messages.insights.tokens_n', [
                    'tokens' => number_format((int) (clone $today)->sum('tokens')),
                ]))
                ->chart(Insights::daily(14)['turns']),

            Stat::make(__('sidekick::messages.insights.turns_month'), number_format($monthTurns))
                ->description(__('sidekick::messages.insights.tokens_n', [
                    'tokens' => number_format((int) (clone $month)->sum('tokens')),
                ])),

            Stat::make(__('sidekick::messages.insights.people_month'), number_format(
                (clone $month)->distinct()->count('user_id'),
            ))
                ->description(__('sidekick::messages.insights.denied_n', ['count' => number_format($denied)])),

            // A failure rate is the number worth watching: it is the one that means something is broken.
            Stat::make(
                __('sidekick::messages.insights.failure_rate'),
                $monthTurns === 0 ? '—' : round(($failed / $monthTurns) * 100, 1).'%',
            )
                ->description(__('sidekick::messages.insights.failed_n', ['count' => number_format($failed)]))
                ->color($monthTurns > 0 && ($failed / $monthTurns) > 0.05 ? 'danger' : 'success'),
        ];
    }
}
