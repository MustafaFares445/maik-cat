<?php

namespace App\Filament\Resources\AdminNotificationCampaigns\Pages;

use App\Filament\Pages\SendNotifications;
use App\Filament\Resources\AdminNotificationCampaigns\AdminNotificationCampaignResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListAdminNotificationCampaigns extends ListRecords
{
    protected static string $resource = AdminNotificationCampaignResource::class;

    public function getSubheading(): ?string
    {
        return 'Review previously sent campaigns and delivery outcomes.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendNotification')
                ->label('Send notification')
                ->icon('heroicon-o-paper-airplane')
                ->url(SendNotifications::getUrl())
                ->visible(fn (): bool => auth()->user()?->can('send_notifications') ?? false),
        ];
    }
}
