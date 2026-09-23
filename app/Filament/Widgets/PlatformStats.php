<?php

namespace App\Filament\Widgets;

use App\Models\AdminNotificationCampaign;
use App\Models\Item;
use App\Models\MetalPrice;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class PlatformStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $latestMetalPrice = MetalPrice::query()->latest('fetched_at')->first();
        $activeAppUsers = User::query()
            ->appUsers()
            ->where('is_active', true)
            ->count();

        $latestPt = $latestMetalPrice?->pt_usd_per_oz;
        $latestPriceDescription = $latestMetalPrice?->fetched_at
            ? 'Stored market snapshot updated '.$latestMetalPrice->fetched_at->diffForHumans()
            : 'Waiting for the first stored market snapshot';

        return [
            Stat::make('Total Items', number_format(Item::query()->count()))
                ->description('Total catalog records stored in the system')
                ->icon('heroicon-o-rectangle-stack')
                ->color('primary'),
            Stat::make('Active App Users', number_format($activeAppUsers))
                ->description('Active end-user accounts with mobile access')
                ->icon('heroicon-o-users')
                ->color('success'),
            Stat::make('Saved Items', number_format(DB::table('saved_items')->count()))
                ->description('Total bookmarks created by end users')
                ->icon('heroicon-o-heart')
                ->color('warning'),
            Stat::make('Sent Campaigns', number_format(AdminNotificationCampaign::query()->where('status', 'sent')->count()))
                ->description('Campaigns delivered from the dashboard communication center')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary'),
            Stat::make(
                'Latest PT (USD/Oz)',
                is_numeric($latestPt) ? number_format((float) $latestPt, 2) : 'N/A'
            )
                ->description($latestPriceDescription)
                ->icon('heroicon-o-chart-bar-square')
                ->color('gray'),
        ];
    }
}
