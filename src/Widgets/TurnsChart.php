<?php

namespace Devletes\Sidekick\Widgets;

use Devletes\Sidekick\Support\Insights;
use Filament\Widgets\ChartWidget;

class TurnsChart extends ChartWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        return __('sidekick::messages.insights.chart_heading');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $daily = Insights::daily(30);

        return [
            'labels' => $daily['labels'],
            'datasets' => [
                [
                    'label' => __('sidekick::messages.insights.turns'),
                    'data' => $daily['turns'],
                    'fill' => true,
                ],
                [
                    'label' => __('sidekick::messages.insights.tokens'),
                    'data' => $daily['tokens'],
                    // Tokens outscale turns by orders of magnitude, so they get their own axis.
                    'yAxisID' => 'tokens',
                    'fill' => false,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['position' => 'left', 'beginAtZero' => true],
                'tokens' => [
                    'position' => 'right',
                    'beginAtZero' => true,
                    'grid' => ['drawOnChartArea' => false],
                ],
            ],
        ];
    }
}
