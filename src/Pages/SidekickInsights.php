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

/**
 * Operator view of what the assistant has been doing: turns, tokens, failures and recent activity, scoped to
 * the panel's tenant.
 *
 * Everything here is a normal Filament page, so change it the normal Filament way — extend it, override what
 * you want, and hand the subclass to the panel:
 *
 *     class NyraInsights extends SidekickInsights
 *     {
 *         protected static ?string $navigationLabel = 'Nyra Insights';
 *         protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-sparkles';
 *     }
 *
 *     SidekickPlugin::make()->enableInsights()->insightsPage(NyraInsights::class)
 *
 * That reaches the navigation, the route, the heading, the widget list and the header actions — rather than
 * only the handful of things a config block could have anticipated.
 */
class SidekickInsights extends Page
{
    protected static bool $isDiscovered = false;

    protected static ?int $navigationSort = 90;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    // No slug or route override: Filament derives /sidekick-insights from the class name, and a subclass
    // sets $slug like any other page.

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

    /** Override in a subclass to reorder these, drop one, or add your own. */
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
