<?php

namespace App\Filament\Resources\ItemFilterMappings\Pages;

use App\Filament\Resources\ItemFilterMappings\ItemFilterMappingResource;
use App\Models\ItemFilterMapping;
use Filament\Resources\Pages\CreateRecord;

class CreateItemFilterMapping extends CreateRecord
{
    protected static string $resource = ItemFilterMappingResource::class;

    public function getTitle(): string
    {
        return 'Add manual filter mapping';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['detection_method'] = 'manual';
        $data['confidence'] = 'manual';
        $data['status'] = ItemFilterMapping::STATUS_NEEDS_REVIEW;
        $data['approved_by'] = null;
        $data['approved_at'] = null;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return ItemFilterMappingResource::getUrl('index');
    }
}
