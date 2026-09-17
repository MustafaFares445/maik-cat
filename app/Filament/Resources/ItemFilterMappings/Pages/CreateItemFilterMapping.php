<?php

namespace App\Filament\Resources\ItemFilterMappings\Pages;

use App\Filament\Resources\ItemFilterMappings\ItemFilterMappingResource;
use App\Models\ItemFilterMapping;
use Filament\Resources\Pages\CreateRecord;

class CreateItemFilterMapping extends CreateRecord
{
    protected static string $resource = ItemFilterMappingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['detection_method'] = $data['detection_method'] ?? 'manual';
        $data['confidence'] = $data['confidence'] ?? 'manual';
        $data['status'] = $data['status'] ?? ItemFilterMapping::STATUS_NEEDS_REVIEW;

        if ($data['status'] === ItemFilterMapping::STATUS_APPROVED) {
            $data['approved_by'] = auth()->id();
            $data['approved_at'] = now();
        }

        return $data;
    }
}
