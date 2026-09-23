<?php

namespace App\Filament\Widgets;

use App\Models\MetalPrice;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Collection;

class MetalTrendsChart extends ChartWidget
{
    protected ?string $heading = '14-Day Metal Price History';

    protected ?string $maxHeight = '360px';

    protected ?string $pollingInterval = '120s';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $points = MetalPrice::query()
            ->where('fetched_at', '>=', now()->subDays(13)->startOfDay())
            ->orderBy('fetched_at')
            ->get()
            ->groupBy(fn (MetalPrice $price): string => $price->fetched_at->toDateString())
            ->map(fn (Collection $prices): MetalPrice => $prices->last())
            ->values();

        return [
            'labels' => $points
                ->map(fn (MetalPrice $price): string => $price->fetched_at->format('Y-m-d'))
                ->all(),
            'datasets' => [
                [
                    'label' => 'PT USD/Oz',
                    'data' => $points->map(fn (MetalPrice $price): float => (float) $price->pt_usd_per_oz)->all(),
                    'borderColor' => '#B45309',
                    'backgroundColor' => 'rgba(180,83,9,0.1)',
                    'fill' => false,
                    'tension' => 0.2,
                ],
                [
                    'label' => 'PD USD/Oz',
                    'data' => $points->map(fn (MetalPrice $price): float => (float) $price->pd_usd_per_oz)->all(),
                    'borderColor' => '#1D4ED8',
                    'backgroundColor' => 'rgba(29,78,216,0.1)',
                    'fill' => false,
                    'tension' => 0.2,
                ],
                [
                    'label' => 'RH USD/Oz',
                    'data' => $points->map(fn (MetalPrice $price): float => (float) $price->rh_usd_per_oz)->all(),
                    'borderColor' => '#374151',
                    'backgroundColor' => 'rgba(55,65,81,0.1)',
                    'fill' => false,
                    'tension' => 0.2,
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    public static function canView(): bool
    {
        return auth()->user()?->can('view_statistics') ?? false;
    }
}
