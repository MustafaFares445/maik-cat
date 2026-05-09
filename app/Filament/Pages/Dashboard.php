<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\MetalTrendsChart;
use App\Filament\Widgets\PlatformStats;
use Filament\Widgets\Widget;

class Dashboard extends \Filament\Pages\Dashboard
{
    protected static string|\UnitEnum|null $navigationGroup = 'Insights';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_statistics') ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Track key platform metrics and market movement at a glance.';
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
}
