<?php

namespace App\Filament\Resources\AdminNotificationCampaigns\Pages;

use App\Filament\Resources\AdminNotificationCampaigns\AdminNotificationCampaignResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAdminNotificationCampaign extends EditRecord
{
    protected static string $resource = AdminNotificationCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
