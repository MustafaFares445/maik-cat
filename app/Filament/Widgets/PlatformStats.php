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
        $activeAppUsers = User::query()->role('app_user')->where('is_active', true)->count();

        return [
            Stat::make('Total Items', number_format(Item::query()->count()))
                ->description('Catalog entries available to the mobile app')
                ->icon('heroicon-o-rectangle-stack')
                ->color('primary'),
            Stat::make('Active App Users', number_format($activeAppUsers))
                ->description('Users eligible for push notifications')
                ->icon('heroicon-o-users')
                ->color('success'),
            Stat::make('Saved Items', number_format(DB::table('saved_items')->count()))
                ->description('Bookmarked converters by end users')
                ->icon('heroicon-o-heart')
                ->color('warning'),
            Stat::make('Sent Campaigns', number_format(AdminNotificationCampaign::query()->where('status', 'sent')->count()))
                ->description('Delivered from dashboard communication center')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary'),
            Stat::make(
                'Latest PT (USD/Oz)',
                $latestMetalPrice ? number_format((float) $latestMetalPrice->pt_usd_per_oz, 2) : 'N/A'
            )
                ->description('Most recent tracked platinum market price')
                ->icon('heroicon-o-chart-bar-square')
                ->color('gray'),
        ];
    }
}
