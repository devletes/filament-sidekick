<?php

namespace Devletes\Sidekick\Tests\Fixtures\Pages;

use BackedEnum;
use Devletes\Sidekick\Pages\SidekickInsights;
use Devletes\Sidekick\Tests\Fixtures\Widgets\CustomRecentRuns;
use Devletes\Sidekick\Widgets\UsageOverview;
use UnitEnum;

/** A host renaming and reshaping the page the ordinary Filament way — no package API involved. */
class NyraInsights extends SidekickInsights
{
    protected static ?string $navigationLabel = 'Nyra Insights';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?int $navigationSort = 42;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $slug = 'nyra-usage';

    protected static bool $shouldRegisterNavigation = false;

    protected function getHeaderWidgets(): array
    {
        return [UsageOverview::class, CustomRecentRuns::class];
    }
}
