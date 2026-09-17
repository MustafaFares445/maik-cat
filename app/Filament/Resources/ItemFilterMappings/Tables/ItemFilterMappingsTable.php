<?php

namespace App\Filament\Resources\ItemFilterMappings\Tables;

use App\Models\ItemFilterMapping;
use App\Services\Mobile\ItemPriceService;
use App\Services\Pricing\FilterPriceCorrectionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ItemFilterMappingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('item.carGroup.name')
                    ->label('Group')
                    ->badge()
                    ->sortable(),
                TextColumn::make('item.serial_code')
                    ->label('Serial')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('item.weight_kg')
                    ->label('Original W')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 3).' kg'),
                TextColumn::make('filter_serial')
                    ->label('Filter')
                    ->searchable()
                    ->placeholder('Manual mapping needed'),
                TextColumn::make('filter_weight')
                    ->label('Filter W')
                    ->getStateUsing(fn (ItemFilterMapping $record): ?string => self::formattedWeight(self::filterWeight($record))),
                TextColumn::make('net_weight')
                    ->label('Net W')
                    ->getStateUsing(fn (ItemFilterMapping $record): ?string => self::formattedWeight(self::netWeight($record))),
                TextColumn::make('correction_status')
                    ->label('Correction')
                    ->getStateUsing(fn (ItemFilterMapping $record): string => self::correctionStatus($record))
                    ->badge(),
                TextColumn::make('current_price')
                    ->label('Current')
                    ->getStateUsing(fn (ItemFilterMapping $record): float => self::price($record, FilterPriceCorrectionService::MODE_DISABLED))
                    ->money('USD'),
                TextColumn::make('weight_only_price')
                    ->label('Weight only')
                    ->getStateUsing(fn (ItemFilterMapping $record): float => self::price($record, FilterPriceCorrectionService::MODE_WEIGHT_ONLY))
                    ->money('USD'),
                TextColumn::make('weight_metals_price')
                    ->label('Weight + metals')
                    ->getStateUsing(fn (ItemFilterMapping $record): float => self::price($record, FilterPriceCorrectionService::MODE_WEIGHT_AND_METALS))
                    ->money('USD'),
                TextColumn::make('confidence')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('item.source_url')
                    ->label('Source')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Open' : '-')
                    ->url(fn (ItemFilterMapping $record): ?string => $record->item?->source_url)
                    ->openUrlInNewTab(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    ItemFilterMapping::STATUS_DETECTED => 'Detected',
                    ItemFilterMapping::STATUS_NEEDS_REVIEW => 'Needs review',
                    ItemFilterMapping::STATUS_APPROVED => 'Approved',
                    ItemFilterMapping::STATUS_IGNORED => 'Ignored',
                ]),
                SelectFilter::make('confidence')->options([
                    'high' => 'High',
                    'medium' => 'Medium',
                    'low' => 'Low',
                    'manual' => 'Manual',
                ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ItemFilterMapping $record): bool => $record->status !== ItemFilterMapping::STATUS_APPROVED && self::canApprove($record))
                    ->action(function (ItemFilterMapping $record): void {
                        if (! self::canApprove($record)) {
                            Notification::make()->title('Filter mapping is not safe to approve')->danger()->send();

                            return;
                        }

                        $record->update([
                            'status' => ItemFilterMapping::STATUS_APPROVED,
                            'approved_by' => auth()->id(),
                            'approved_at' => now(),
                        ]);

                        Notification::make()->title('Filter mapping approved')->success()->send();
                    }),
                Action::make('ignore')
                    ->label('Ignore')
                    ->icon('heroicon-o-no-symbol')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (ItemFilterMapping $record): bool => $record->status !== ItemFilterMapping::STATUS_IGNORED)
                    ->action(function (ItemFilterMapping $record): void {
                        $record->update(['status' => ItemFilterMapping::STATUS_IGNORED]);
                        Notification::make()->title('Candidate ignored')->success()->send();
                    }),
                EditAction::make(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    private static function canApprove(ItemFilterMapping $record): bool
    {
        return self::weightOnlyAssay($record)['applied'] ?? false;
    }

    private static function correctionStatus(ItemFilterMapping $record): string
    {
        $assay = self::weightOnlyAssay($record);

        return ($assay['applied'] ?? false) ? 'Ready' : (string) ($assay['reason'] ?? 'Needs review');
    }

    /** @return array<string, mixed> */
    private static function weightOnlyAssay(ItemFilterMapping $record): array
    {
        if ($record->item === null) {
            return ['applied' => false, 'reason' => 'missing_item'];
        }

        return app(FilterPriceCorrectionService::class)
            ->effectiveAssayForMapping($record->item, $record, FilterPriceCorrectionService::MODE_WEIGHT_ONLY);
    }

    private static function filterWeight(ItemFilterMapping $record): ?float
    {
        if ($record->filter_weight_override !== null) {
            return (float) $record->filter_weight_override;
        }

        if ($record->item === null) {
            return null;
        }

        return app(FilterPriceCorrectionService::class)->filterProfile($record->item, $record)['weight_kg'];
    }

    private static function netWeight(ItemFilterMapping $record): ?float
    {
        if ($record->item === null) {
            return null;
        }

        $assay = app(FilterPriceCorrectionService::class)
            ->effectiveAssayForMapping($record->item, $record, FilterPriceCorrectionService::MODE_WEIGHT_ONLY);

        return $assay['applied'] ? (float) $assay['weight_kg'] : null;
    }

    private static function price(ItemFilterMapping $record, string $mode): float
    {
        if ($record->item === null) {
            return 0.0;
        }

        return app(ItemPriceService::class)->priceForMappingPreview($record->item, $record, $mode, 'USD');
    }

    private static function formattedWeight(?float $weight): ?string
    {
        return $weight === null ? null : number_format($weight, 3).' kg';
    }
}
