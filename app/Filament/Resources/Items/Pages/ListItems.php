<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Items\Widgets\ItemCatalogStats;
use App\Services\Mobile\ItemApiSettingsService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListItems extends ListRecords
{
    protected static string $resource = ItemResource::class;

    public function getSubheading(): ?string
    {
        if (app(ItemApiSettingsService::class)->uniqueSerialItemsEnabled()) {
            return 'Grouped app view is active. Each serial shows the item customers see, with its related stored items underneath. Use the chevron to expand or collapse a group.';
        }

        return 'Manage converter items, technical specs, and app-ready images.';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ItemCatalogStats::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
