<?php

namespace App\Filament\Resources\CarGroups\Pages;

use App\Filament\Resources\CarGroups\CarGroupResource;
use App\Models\CarGroup;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class CreateCarGroup extends CreateRecord
{
    protected static string $resource = CarGroupResource::class;

    protected ?string $uploadedImagePath = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->uploadedImagePath = $this->extractUploadPath($data['car_group_image'] ?? null);
        unset($data['car_group_image']);
        $data['excel_sheet_name'] = $data['name'];

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var CarGroup $record */
        $record = $this->getRecord();
        $this->syncUploadedImage($record);
    }

    private function extractUploadPath(mixed $uploaded): ?string
    {
        if (is_array($uploaded)) {
            $uploaded = Arr::first($uploaded);
        }

        if (! is_string($uploaded) || $uploaded === '') {
            return null;
        }

        return $uploaded;
    }

    private function syncUploadedImage(CarGroup $record): void
    {
        if (! is_string($this->uploadedImagePath) || $this->uploadedImagePath === '') {
            return;
        }

        $absolutePath = Storage::disk('public')->path($this->uploadedImagePath);

        if (! is_file($absolutePath)) {
            return;
        }

        $record->clearMediaCollection('images');
        $record->addMedia($absolutePath)->toMediaCollection('images');
    }
}
