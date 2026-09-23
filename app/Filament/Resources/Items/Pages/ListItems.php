<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Items\Widgets\ItemCatalogStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListItems extends ListRecords
{
    protected static string $resource = ItemResource::class;

    public function getSubheading(): ?string
    {
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
