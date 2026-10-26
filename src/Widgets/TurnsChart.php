<?php

namespace Devletes\Sidekick\Widgets;

use Devletes\Sidekick\Support\Insights;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Filament\Widgets\ChartWidget;

class TurnsChart extends ChartWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    /** Without a fixed height Chart.js keeps its 2:1 ratio and a full-width chart grows huge. */
    protected ?string $maxHeight = '320px';

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

        // Concrete rgb from the panel palette: Chart.js draws on a canvas and cannot read CSS variables.
        $turns = $this->rgb('primary', 500, 'rgb(59, 130, 246)');
        $tokens = $this->rgb('warning', 500, 'rgb(245, 158, 11)');

        return [
            'labels' => $daily['labels'],
            'datasets' => [
                [
                    'label' => __('sidekick::messages.insights.turns'),
                    'data' => $daily['turns'],
                    'borderColor' => $turns,
                    'pointBackgroundColor' => $turns,
                    'backgroundColor' => $this->alpha($turns, 0.15),
                    'fill' => true,
                ],
                [
                    'label' => __('sidekick::messages.insights.tokens'),
                    'data' => $daily['tokens'],
                    // Tokens outscale turns by orders of magnitude, so they get their own axis.
                    'yAxisID' => 'tokens',
                    'borderColor' => $tokens,
                    'pointBackgroundColor' => $tokens,
                    'backgroundColor' => $tokens,
                    'fill' => false,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            // Required for $maxHeight to win over Chart.js's aspect ratio.
            'maintainAspectRatio' => false,
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => [
                'legend' => ['labels' => ['usePointStyle' => true, 'boxHeight' => 8]],
            ],
            'scales' => [
                'y' => ['position' => 'left', 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
                'tokens' => [
                    'position' => 'right',
                    'beginAtZero' => true,
                    'border' => ['display' => false],
                    'grid' => ['drawOnChartArea' => false],
                ],
            ],
        ];
    }

    protected function rgb(string $color, int $shade, string $fallback): string
    {
        $value = FilamentColor::getColor($color)[$shade] ?? null;

        return $value ? Color::convertToRgb($value) : $fallback;
    }

    protected function alpha(string $rgb, float $alpha): string
    {
        return str_replace(['rgb(', ')'], ['rgba(', ", {$alpha})"], $rgb);
    }
}
