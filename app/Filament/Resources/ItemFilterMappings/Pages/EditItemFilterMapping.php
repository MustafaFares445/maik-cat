<?php

namespace App\Filament\Resources\ItemFilterMappings\Pages;

use App\Filament\Resources\ItemFilterMappings\ItemFilterMappingResource;
use App\Models\ItemFilterMapping;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditItemFilterMapping extends EditRecord
{
    protected static string $resource = ItemFilterMappingResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['status'] ?? null) === ItemFilterMapping::STATUS_APPROVED) {
            $data['approved_by'] = auth()->id();
            $data['approved_at'] = now();
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
