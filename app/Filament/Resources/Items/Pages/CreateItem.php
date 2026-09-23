<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Resources\Items\ItemResource;
use App\Models\Item;
use App\Services\ItemDashboardImageService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Support\Arr;

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

        app(ItemDashboardImageService::class)
            ->replaceFromPublicUpload($record, $this->uploadedImagePath);
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
}
