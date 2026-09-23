<?php

namespace App\Filament\Resources\Items\Tables;

use App\Models\CarGroup;
use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Services\Mobile\ItemApiSettingsService;
use App\Services\Mobile\ItemPriceService;
use App\Services\Pricing\PricingReviewService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ItemsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->columns([
                ImageColumn::make('image')
                    ->label('Image')
                    ->circular()
                    ->size(48)
                    ->getStateUsing(fn (Item $record): string => $record->getFirstMediaUrl('images', 'thumb')),
                TextColumn::make('serial_code')
                    ->label('Serial')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('model')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('carGroup.name')
                    ->label('Car group')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('weight_kg')
                    ->label('Weight (kg)')
                    ->formatStateUsing(fn (mixed $state): string => self::formatNumber($state, 3))
                    ->sortable(),
                TextColumn::make('pt_ppm')
                    ->label('PT')
                    ->formatStateUsing(fn (mixed $state): string => self::formatNumber($state, 4))
                    ->sortable(),
                TextColumn::make('pd_ppm')
                    ->label('PD')
                    ->formatStateUsing(fn (mixed $state): string => self::formatNumber($state, 4))
                    ->sortable(),
                TextColumn::make('rh_ppm')
                    ->label('RH')
                    ->formatStateUsing(fn (mixed $state): string => self::formatNumber($state, 4))
                    ->sortable(),
                TextColumn::make('current_price')
                    ->label('Price (USD)')
                    ->getStateUsing(fn (Item $record): float => app(ItemPriceService::class)->priceFor($record, 'USD'))
                    ->money('USD'),
                TextColumn::make('pricing_review_status')
                    ->label('Pricing review')
                    ->getStateUsing(fn (Item $record): string => $record->filterMapping?->status === ItemFilterMapping::STATUS_NEEDS_REVIEW ? 'Needs review' : 'Clear')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Needs review' ? 'warning' : 'success'),
                TextColumn::make('pricing_review_reason')
                    ->label('Reason')
                    ->getStateUsing(function (Item $record): ?string {
                        $mapping = $record->filterMapping;

                        if (! $mapping instanceof ItemFilterMapping || $mapping->status !== ItemFilterMapping::STATUS_NEEDS_REVIEW) {
                            return null;
                        }

                        return app(PricingReviewService::class)->issue($mapping)['label'];
                    })
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('car_group_id')
                    ->label('Car group')
                    ->options(fn (): array => CarGroup::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('pricing_review')
                    ->label('Pricing review')
                    ->options([
                        'needs_review' => 'Needs review — blocked from being shown in the app',
                        'clear' => 'Clear',
                    ])
                    ->query(function ($query, array $data) {
                        return match ($data['value'] ?? null) {
                            'needs_review' => $query->whereHas('filterMapping', fn ($mapping) => $mapping->where('status', ItemFilterMapping::STATUS_NEEDS_REVIEW)),
                            'clear' => $query->whereDoesntHave('filterMapping', fn ($mapping) => $mapping->where('status', ItemFilterMapping::STATUS_NEEDS_REVIEW)),
                            default => $query,
                        };
                    }),
                TernaryFilter::make('has_image')
                    ->label('Has image')
                    ->queries(
                        true: fn ($query) => $query->whereHas('media', fn ($mediaQuery) => $mediaQuery->where('collection_name', 'images')),
                        false: fn ($query) => $query->whereDoesntHave('media', fn ($mediaQuery) => $mediaQuery->where('collection_name', 'images')),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');

        $activeTab = $table->getLivewire()->activeTab ?? null;
        $groupedView = app(ItemApiSettingsService::class)->uniqueSerialItemsEnabled()
            && ($activeTab === null || $activeTab === 'grouped');

        if ($groupedView) {
            $table
                ->groups([
                    self::serialGroup(),
                ])
                ->defaultGroup('serial_code')
                ->groupingSettingsHidden();
        }

        return $table;
    }

    private static function serialGroup(): Group
    {
        return Group::make('serial_code')
            ->label('Serial')
            ->titlePrefixedWithLabel(false)
            ->getTitleFromRecordUsing(fn (Item $record): string => self::displayValue($record->serial_code))
            ->getDescriptionFromRecordUsing(fn (Item $record): string => self::groupDescription($record))
            ->scopeQueryByKeyUsing(
                fn (Builder $query, string $key): Builder => $query->where('serial_code', $key),
            )
            ->orderQueryUsing(
                fn (Builder $query, string $direction): Builder => $query->orderBy('serial_code', $direction),
            )
            ->collapsible();
    }

    private static function groupDescription(Item $record): string
    {
        $serial = trim((string) $record->serial_code);

        $siblings = Item::query()
            ->with(['carGroup', 'media', 'filterMapping'])
            ->where('serial_code', $serial)
            ->orderByDesc('created_at')
            ->get();

        $representative = $siblings
            ->first(fn (Item $item): bool => $item->isApiVisible());

        if (! $representative instanceof Item) {
            return sprintf(
                'Not currently shown in the app · %d related stored items',
                $siblings->count(),
            );
        }

        $calculable = $siblings->filter(
            fn (Item $item): bool => (float) $item->weight_kg > 0
                && ((float) $item->pt_ppm > 0 || (float) $item->pd_ppm > 0 || (float) $item->rh_ppm > 0),
        );

        $averagePrice = $calculable->isEmpty()
            ? app(ItemPriceService::class)->priceFor($representative, 'USD')
            : (float) $calculable
                ->map(fn (Item $item): float => app(ItemPriceService::class)->priceFor($item, 'USD'))
                ->avg();

        return sprintf(
            '%d related items · App average $%s · %s · %s kg · PT %s · PD %s · RH %s',
            $siblings->count(),
            number_format($averagePrice, 2),
            self::displayValue($representative->carGroup?->name),
            self::formatNumber($representative->weight_kg, 3),
            self::formatNumber($representative->pt_ppm, 4),
            self::formatNumber($representative->pd_ppm, 4),
            self::formatNumber($representative->rh_ppm, 4),
        );
    }

    private static function formatNumber(mixed $value, int $maxDecimals): string
    {
        if (! is_numeric($value)) {
            return '—';
        }

        $formatted = number_format((float) $value, $maxDecimals, '.', ',');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private static function displayValue(mixed $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : '—';
    }
}
