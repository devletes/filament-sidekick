<?php

namespace Devletes\Sidekick\Pages;

use BackedEnum;
use Devletes\Sidekick\SidekickPlugin;
use Devletes\Sidekick\Widgets\RecentRuns;
use Devletes\Sidekick\Widgets\TenantUsage;
use Devletes\Sidekick\Widgets\TurnsChart;
use Devletes\Sidekick\Widgets\UsageOverview;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/** Customise by subclassing and passing the subclass to SidekickPlugin::insightsPage(). */
class SidekickInsights extends Page
{
    protected static bool $isDiscovered = false;

    protected static ?int $navigationSort = 90;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    public static function getNavigationLabel(): string
    {
        return static::$navigationLabel ?? __('sidekick::messages.insights.title');
    }

    public function getTitle(): string|Htmlable
    {
        return static::$title ?? static::getNavigationLabel();
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
            // Hides itself wherever the split would be a single row.
            TenantUsage::class,
            RecentRuns::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 4;
    }
}
