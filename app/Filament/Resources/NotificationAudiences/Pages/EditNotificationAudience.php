<?php

namespace App\Filament\Resources\NotificationAudiences\Pages;

use App\Filament\Resources\NotificationAudiences\NotificationAudienceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNotificationAudience extends EditRecord
{
    protected static string $resource = NotificationAudienceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
