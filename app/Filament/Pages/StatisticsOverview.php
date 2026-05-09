<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\MetalTrendsChart;
use App\Filament\Widgets\PlatformStats;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;

class StatisticsOverview extends Dashboard
{
    protected static ?string $title = 'Statistics';

    protected static ?string $navigationLabel = 'Statistics';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|\UnitEnum|null $navigationGroup = 'Insights';

    protected static string $routePath = 'statistics';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_statistics') ?? false;
    }

    /**
     * @return array<class-string<Widget>>
     */
    public function getWidgets(): array
    {
        return [
            PlatformStats::class,
            MetalTrendsChart::class,
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Track key platform metrics and market movement at a glance.';
    }
}
