<?php

namespace App\Filament\Resources\ItemFilterMappings\Tables;

use App\Filament\Resources\Items\ItemResource;
use App\Models\ItemFilterMapping;
use App\Services\Mobile\ItemPriceService;
use App\Services\Pricing\FilterPriceCorrectionService;
use App\Services\Pricing\PricingReviewService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use RuntimeException;

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
                TextColumn::make('issue')
                    ->label('Review issue')
                    ->getStateUsing(fn (ItemFilterMapping $record): string => app(PricingReviewService::class)->issue($record)['label'])
                    ->badge()
                    ->color(fn (ItemFilterMapping $record): string => $record->status === ItemFilterMapping::STATUS_NEEDS_REVIEW ? 'warning' : 'gray')
                    ->wrap(),
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
                TextColumn::make('api_status')
                    ->label('Shown in app')
                    ->getStateUsing(fn (ItemFilterMapping $record): string => $record->status === ItemFilterMapping::STATUS_NEEDS_REVIEW ? 'No — needs review' : 'Yes')
                    ->badge()
                    ->color(fn (string $state): string => str_starts_with($state, 'No') ? 'danger' : 'success'),
                TextColumn::make('confidence')->badge()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        ItemFilterMapping::STATUS_NEEDS_REVIEW => 'warning',
                        ItemFilterMapping::STATUS_APPROVED => 'success',
                        ItemFilterMapping::STATUS_IGNORED => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('item.source_url')
                    ->label('Source')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Open' : '-')
                    ->url(fn (ItemFilterMapping $record): ?string => $record->item?->source_url)
                    ->openUrlInNewTab(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Review status')
                    ->default(ItemFilterMapping::STATUS_NEEDS_REVIEW)
                    ->options([
                        ItemFilterMapping::STATUS_NEEDS_REVIEW => 'Needs review — blocked from being shown in the app',
                        ItemFilterMapping::STATUS_APPROVED => 'Approved',
                        ItemFilterMapping::STATUS_IGNORED => 'Reviewed — current pricing kept',
                        ItemFilterMapping::STATUS_DETECTED => 'Detected',
                    ]),
                SelectFilter::make('confidence')->options([
                    'high' => 'High',
                    'medium' => 'Medium',
                    'low' => 'Low',
                    'manual' => 'Manual',
                ]),
            ])
            ->recordActions([
                self::reviewWeightAction(),
                Action::make('editItemData')
                    ->label('Edit item data')
                    ->icon('heroicon-o-pencil-square')
                    ->color('info')
                    ->visible(fn (ItemFilterMapping $record): bool => $record->status === ItemFilterMapping::STATUS_NEEDS_REVIEW && $record->item !== null && ItemResource::canEdit($record->item))
                    ->url(fn (ItemFilterMapping $record): string => ItemResource::getUrl('edit', ['record' => $record->item]))
                    ->openUrlInNewTab(),
                self::keepCurrentPricingAction(),
                EditAction::make(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    private static function reviewWeightAction(): Action
    {
        return Action::make('reviewAndApplyWeight')
            ->label('Review & apply')
            ->icon('heroicon-o-scale')
            ->color('warning')
            ->modalHeading('Review linked pricing before approval')
            ->modalSubmitActionLabel('Approve & apply to linked items')
            ->visible(function (ItemFilterMapping $record): bool {
                if ($record->status !== ItemFilterMapping::STATUS_NEEDS_REVIEW) {
                    return false;
                }

                return app(PricingReviewService::class)->issue($record)['type'] === 'component_weight';
            })
            ->schema([
                Section::make('Issue')
                    ->compact()
                    ->schema([
                        Text::make(function (ItemFilterMapping $record): string {
                            $issue = app(PricingReviewService::class)->issue($record);

                            return $issue['label'].($issue['variant'] ? ' · '.$issue['variant'] : '');
                        })->weight('bold'),
                        Text::make(fn (ItemFilterMapping $record): string => app(PricingReviewService::class)->issue($record)['instruction']),
                    ]),
                TextInput::make('filter_weight_kg')
                    ->label('Verified DPF / filter component weight')
                    ->numeric()
                    ->minValue(0.001)
                    ->step(0.001)
                    ->suffix('kg')
                    ->required()
                    ->live(debounce: 350)
                    ->helperText('Enter the measured component weight once. It will be previewed and, after approval, applied to every linked Needs Review item in the same group and serial family.'),
                Section::make('Before / after preview')
                    ->description('Nothing is saved until you approve the modal.')
                    ->schema([
                        Text::make(function (Get $get, ItemFilterMapping $record): HtmlString {
                            $weight = $get('filter_weight_kg');

                            if (! is_numeric($weight) || (float) $weight <= 0) {
                                return new HtmlString('<strong>Enter a verified component weight to calculate the preview.</strong>');
                            }

                            $preview = app(PricingReviewService::class)->previewFamilyWeight($record, (float) $weight);
                            $status = $preview['all_safe']
                                ? '<span style="color:#15803d"><strong>Safe to apply to all linked items.</strong></span>'
                                : '<span style="color:#b91c1c"><strong>Not safe to approve yet. At least one linked item failed the weight safety rules.</strong></span>';

                            return new HtmlString($status.'<br>Linked items: <strong>'.$preview['count'].'</strong>');
                        }),
                        UnorderedList::make(function (Get $get, ItemFilterMapping $record): array {
                            $weight = $get('filter_weight_kg');

                            if (! is_numeric($weight) || (float) $weight <= 0) {
                                return ['Preview will appear here after entering the verified component weight.'];
                            }

                            $preview = app(PricingReviewService::class)->previewFamilyWeight($record, (float) $weight);

                            return collect($preview['rows'])
                                ->map(function (array $row): string {
                                    $model = filled($row['model']) ? ' · '.$row['model'] : '';
                                    $net = $row['net_weight_kg'] === null ? 'invalid' : number_format((float) $row['net_weight_kg'], 3).' kg';
                                    $delta = $row['delta_percent'] === null ? '' : sprintf(' (%+.2f%%)', (float) $row['delta_percent']);
                                    $safety = $row['safe'] ? 'Safe' : 'Blocked: '.$row['reason'];

                                    return sprintf(
                                        '%s%s — weight %.3f kg → %s — $%s → $%s%s — %s',
                                        $row['serial'],
                                        $model,
                                        (float) $row['original_weight_kg'],
                                        $net,
                                        number_format((float) $row['current_price_usd'], 2),
                                        number_format((float) $row['proposed_price_usd'], 2),
                                        $delta,
                                        $safety,
                                    );
                                })
                                ->all();
                        })->columns(1),
                    ]),
                Textarea::make('review_note')
                    ->label('Review note')
                    ->rows(3)
                    ->placeholder('Optional: measurement source, scale reading, OEM/variant confirmation, etc.'),
            ])
            ->action(function (array $data, ItemFilterMapping $record): void {
                try {
                    $result = app(PricingReviewService::class)->applyFamilyWeight(
                        $record,
                        (float) $data['filter_weight_kg'],
                        auth()->id(),
                        filled($data['review_note'] ?? null) ? (string) $data['review_note'] : null,
                    );
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->title('Pricing review was not applied')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Pricing review approved')
                    ->body(sprintf(
                        'Applied the verified component weight to %d linked item(s). They can now be shown in the app.',
                        $result['applied'],
                    ))
                    ->success()
                    ->send();
            });
    }

    private static function keepCurrentPricingAction(): Action
    {
        return Action::make('approveCurrentPricing')
            ->label('Keep current pricing')
            ->icon('heroicon-o-check-badge')
            ->color('gray')
            ->modalHeading('Approve current pricing without a filter correction')
            ->modalDescription('Use this only after verifying that no component-weight correction should be applied. The linked items can then be shown in the app with their existing pricing.')
            ->modalSubmitActionLabel('Approve current pricing')
            ->visible(fn (ItemFilterMapping $record): bool => $record->status === ItemFilterMapping::STATUS_NEEDS_REVIEW)
            ->schema([
                Text::make(function (ItemFilterMapping $record): string {
                    $issue = app(PricingReviewService::class)->issue($record);
                    $count = app(PricingReviewService::class)->relatedReviewMappings($record)->count();

                    return $issue['label'].' · '.$count.' linked Needs Review item(s)';
                })->weight('bold'),
                Text::make(fn (ItemFilterMapping $record): string => app(PricingReviewService::class)->issue($record)['instruction']),
                Textarea::make('review_note')
                    ->label('Why is the current pricing safe to keep?')
                    ->required()
                    ->rows(4)
                    ->helperText('This note is stored as audit evidence and applies to all linked review items.'),
            ])
            ->action(function (array $data, ItemFilterMapping $record): void {
                try {
                    $count = app(PricingReviewService::class)->approveCurrentPricing(
                        $record,
                        auth()->id(),
                        (string) $data['review_note'],
                    );
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->title('Review could not be completed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Current pricing approved')
                    ->body("Reviewed {$count} linked item(s). They can now be shown in the app.")
                    ->success()
                    ->send();
            });
    }

    private static function correctionStatus(ItemFilterMapping $record): string
    {
        if ($record->status === ItemFilterMapping::STATUS_NEEDS_REVIEW) {
            return 'Needs review';
        }

        $assay = self::weightOnlyAssay($record);

        return ($assay['applied'] ?? false) ? 'Ready' : (string) ($assay['reason'] ?? 'No correction');
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
