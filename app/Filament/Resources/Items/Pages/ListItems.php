<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Items\Widgets\ItemCatalogStats;
use App\Services\Mobile\ItemApiSettingsService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListItems extends ListRecords
{
    protected static string $resource = ItemResource::class;

    public function getSubheading(): ?string
    {
        if (app(ItemApiSettingsService::class)->uniqueSerialItemsEnabled()) {
            return 'Grouped items is the default view. It only groups serial codes that have more than one stored item. Use All items to see the full catalog without grouping.';
        }

        return 'Manage converter items, technical specs, and app-ready images.';
    }

    public function getTabs(): array
    {
        if (! app(ItemApiSettingsService::class)->uniqueSerialItemsEnabled()) {
            return [
                'all' => Tab::make('All items'),
            ];
        }

        return [
            'grouped' => Tab::make('Grouped items')
                ->icon('heroicon-o-rectangle-stack')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereNotNull('serial_code')
                    ->where('serial_code', '!=', '')
                    ->whereIn('serial_code', function ($subquery): void {
                        $subquery
                            ->select('serial_code')
                            ->from('items')
                            ->whereNotNull('serial_code')
                            ->where('serial_code', '!=', '')
                            ->groupBy('serial_code')
                            ->havingRaw('COUNT(*) > 1');
                    })),
            'all' => Tab::make('All items')
                ->icon('heroicon-o-list-bullet'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return app(ItemApiSettingsService::class)->uniqueSerialItemsEnabled()
            ? 'grouped'
            : 'all';
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
