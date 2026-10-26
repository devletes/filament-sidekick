<?php

namespace Devletes\Sidekick\Tests\Fixtures\Pages;

use BackedEnum;
use Devletes\Sidekick\Pages\SidekickInsights;
use Devletes\Sidekick\Tests\Fixtures\Widgets\CustomRecentRuns;
use Devletes\Sidekick\Widgets\UsageOverview;
use UnitEnum;

class AssistantInsights extends SidekickInsights
{
    protected static ?string $navigationLabel = 'Assistant Insights';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?int $navigationSort = 42;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $slug = 'assistant-usage';

    protected static bool $shouldRegisterNavigation = false;

    protected function getHeaderWidgets(): array
    {
        return [UsageOverview::class, CustomRecentRuns::class];
    }
}
