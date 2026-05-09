<?php

namespace App\Filament\Resources\NotificationAudiences\Pages;

use App\Filament\Resources\NotificationAudiences\NotificationAudienceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNotificationAudience extends CreateRecord
{
    protected static string $resource = NotificationAudienceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
