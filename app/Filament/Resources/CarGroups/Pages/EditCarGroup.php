<?php

namespace App\Filament\Resources\CarGroups\Pages;

use App\Filament\Resources\CarGroups\CarGroupResource;
use App\Models\CarGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class EditCarGroup extends EditRecord
{
    protected static string $resource = CarGroupResource::class;

    protected ?string $uploadedImagePath = null;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->uploadedImagePath = $this->extractUploadPath($data['car_group_image'] ?? null);
        unset($data['car_group_image']);
        $data['excel_sheet_name'] = $data['name'];

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var CarGroup $record */
        $record = $this->getRecord();
        $this->syncUploadedImage($record);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
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
