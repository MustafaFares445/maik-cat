<?php

namespace App\Filament\Resources\ItemFilterMappings\Pages;

use App\Filament\Resources\ItemFilterMappings\ItemFilterMappingResource;
use App\Models\Item;
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
        $data['status'] = ItemFilterMapping::STATUS_APPROVED;
        $data['approved_by'] = auth()->id();
        $data['approved_at'] = now();

        if (filled($data['filter_item_id'] ?? null)) {
            $data['filter_serial'] = Item::query()
                ->whereKey($data['filter_item_id'])
                ->value('serial_code');
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return ItemFilterMappingResource::getUrl('index');
    }
}
