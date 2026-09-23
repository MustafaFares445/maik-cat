<?php

namespace App\Filament\Resources\Items\Tables;

use App\Models\CarGroup;
use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Services\Mobile\ItemPriceService;
use App\Services\Pricing\PricingReviewService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
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
                    ->numeric(3)
                    ->sortable(),
                TextColumn::make('pt_ppm')
                    ->label('PT')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('pd_ppm')
                    ->label('PD')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('rh_ppm')
                    ->label('RH')
                    ->numeric(4)
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
    }
}
