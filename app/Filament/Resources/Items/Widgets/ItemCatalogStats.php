<?php

namespace App\Filament\Resources\Items\Widgets;

use App\Models\Item;
use App\Models\ItemFilterMapping;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ItemCatalogStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $shownInApp = Item::query()
            ->apiVisible()
            ->count();

        $needsReview = Item::query()
            ->whereHas(
                'filterMapping',
                fn ($query) => $query->where('status', ItemFilterMapping::STATUS_NEEDS_REVIEW),
            )
            ->count();

        $withoutImage = Item::query()
            ->whereDoesntHave(
                'media',
                fn ($query) => $query->where('collection_name', 'images'),
            )
            ->count();

        return [
            Stat::make('Shown in app', number_format($shownInApp))
                ->description('Items currently available to customers')
                ->icon('heroicon-o-eye')
                ->color('success'),
            Stat::make('Needs review', number_format($needsReview))
                ->description('Items hidden until their details are checked')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning'),
            Stat::make('No image', number_format($withoutImage))
                ->description('Items missing a catalog image')
                ->icon('heroicon-o-photo')
                ->color('danger'),
        ];
    }
}
