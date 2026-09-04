<?php

namespace Devletes\Sidekick\Pages;

use BackedEnum;
use Devletes\Sidekick\SidekickPlugin;
use Devletes\Sidekick\Widgets\RecentRuns;
use Devletes\Sidekick\Widgets\TurnsChart;
use Devletes\Sidekick\Widgets\UsageOverview;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Operator view of what the assistant has been doing: turns, tokens, failures and recent activity, scoped to
 * the panel's tenant. Registered only when a panel asks for it via SidekickPlugin::enableInsights().
 */
class SidekickInsights extends Page
{
    protected static bool $isDiscovered = false;

    protected static ?int $navigationSort = 90;

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return static::$navigationIcon ?? config('sidekick.insights.icon', 'heroicon-o-chart-bar');
    }

    public static function getNavigationLabel(): string
    {
        return __('sidekick::messages.insights.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('sidekick::messages.insights.title');
    }

    public static function getRoutePath(Panel $panel): string
    {
        return '/'.trim((string) config('sidekick.insights.slug', 'sidekick-insights'), '/');
    }

    /** Enabled is not the same as permitted: a panel can pass its own check to enableInsights(). */
    public static function canAccess(): bool
    {
        if (! config('sidekick.insights.enabled', false)) {
            return false;
        }

        $check = SidekickPlugin::get()?->getInsightsAuthorization();

        return $check === null || (bool) $check(auth()->user());
    }

    protected function getHeaderWidgets(): array
    {
        return [
            UsageOverview::class,
            TurnsChart::class,
            RecentRuns::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 4;
    }
}
