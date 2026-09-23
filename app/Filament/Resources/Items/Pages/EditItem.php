<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Resources\Items\ItemResource;
use App\Models\Item;
use App\Services\ItemDashboardImageService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Support\Arr;

class EditItem extends EditRecord
{
    protected static string $resource = ItemResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected ?string $uploadedImagePath = null;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->uploadedImagePath = $this->extractUploadPath($data['item_image'] ?? null);
        unset($data['item_image']);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Item $record */
        $record = $this->getRecord();

        app(ItemDashboardImageService::class)
            ->replaceFromPublicUpload($record, $this->uploadedImagePath);
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
}
