<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Resources\Items\ItemResource;
use App\Models\Item;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class CreateItem extends CreateRecord
{
    protected static string $resource = ItemResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected ?string $uploadedImagePath = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->uploadedImagePath = $this->extractUploadPath($data['item_image'] ?? null);
        unset($data['item_image']);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Item $record */
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

    private function syncUploadedImage(Item $record): void
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
