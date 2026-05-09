<?php

namespace App\Filament\Widgets;

use App\Services\Mobile\ThirdPartyMarketService;
use Filament\Widgets\ChartWidget;

class MetalTrendsChart extends ChartWidget
{
    protected ?string $heading = '14-Day Metal Price Trend';

    protected ?string $maxHeight = '320px';

    protected ?string $pollingInterval = '120s';

    protected function getData(): array
    {
        /** @var ThirdPartyMarketService $service */
        $service = app(ThirdPartyMarketService::class);
        $points = collect($service->changes(days: 14, currency: 'USD'));
        $labels = $points->pluck('date')->map(
            fn (mixed $date): string => is_string($date) ? $date : now()->toDateString()
        )->all();

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'PT USD/Oz',
                    'data' => $points->pluck('pt_usd_per_oz')->map(fn (mixed $value): float => (float) $value)->all(),
                    'borderColor' => '#B45309',
                    'backgroundColor' => 'rgba(180,83,9,0.1)',
                    'fill' => false,
                ],
                [
                    'label' => 'PD USD/Oz',
                    'data' => $points->pluck('pd_usd_per_oz')->map(fn (mixed $value): float => (float) $value)->all(),
                    'borderColor' => '#1D4ED8',
                    'backgroundColor' => 'rgba(29,78,216,0.1)',
                    'fill' => false,
                ],
                [
                    'label' => 'RH USD/Oz',
                    'data' => $points->pluck('rh_usd_per_oz')->map(fn (mixed $value): float => (float) $value)->all(),
                    'borderColor' => '#374151',
                    'backgroundColor' => 'rgba(55,65,81,0.1)',
                    'fill' => false,
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
