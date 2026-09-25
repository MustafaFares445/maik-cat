<?php

namespace App\Filament\Resources\ItemFilterMappings\Schemas;

use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Services\Mobile\ItemPriceService;
use App\Services\Pricing\FilterPriceCorrectionService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ItemFilterMappingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Manual filter mapping')
                ->description('Choose the catalyst product and the matching filter reference. Saving applies this manual mapping immediately.')
                ->columns(2)
                ->components([
                    Select::make('item_id')
                        ->relationship('item', 'serial_code')
                        ->searchable()
                        ->preload(false)
                        ->required()
                        ->live()
                        ->label('Catalyst product')
                        ->helperText('Select the product whose filter reference needs to be corrected.'),
                    Select::make('filter_item_id')
                        ->relationship('filterItem', 'serial_code')
                        ->searchable()
                        ->preload(false)
                        ->required()
                        ->live()
                        ->label('Matching filter reference')
                        ->helperText('Select the filter-only item that should be used as the pricing reference.'),
                    TextInput::make('filter_weight_override')
                        ->label('Corrected filter weight (optional)')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.001)
                        ->suffix('kg')
                        ->nullable()
                        ->live(debounce: 350)
                        ->helperText('Enter a verified filter weight only when the stored or detected weight is wrong. When provided, this value is applied immediately with the manual mapping.'),
                ]),

            Section::make('Pricing impact preview')
                ->description('Preview the pricing effect before saving. Saving applies the mapping immediately.')
                ->components([
                    Text::make(fn (Get $get): string => self::pricingPreview($get)),
                ]),
        ]);
    }

    private static function pricingPreview(Get $get): string
    {
        $itemId = $get('item_id');

        if (blank($itemId)) {
            return 'Select a catalyst product first to preview the pricing impact.';
        }

        $item = Item::query()->find($itemId);
        if (! $item instanceof Item) {
            return 'The selected catalyst product could not be loaded.';
        }

        $filterItemId = $get('filter_item_id');
        $weightOverride = $get('filter_weight_override');

        if (blank($filterItemId)) {
            return 'Select a matching filter reference to calculate the preview.';
        }

        $mapping = new ItemFilterMapping([
            'item_id' => $item->getKey(),
            'filter_item_id' => (string) $filterItemId,
            'filter_weight_override' => is_numeric($weightOverride) ? (float) $weightOverride : null,
            'detection_method' => 'manual',
            'confidence' => 'manual',
            'status' => ItemFilterMapping::STATUS_APPROVED,
        ]);

        $correctionService = app(FilterPriceCorrectionService::class);
        $assay = $correctionService->effectiveAssayForMapping(
            $item,
            $mapping,
            FilterPriceCorrectionService::MODE_WEIGHT_ONLY,
        );

        $priceService = app(ItemPriceService::class);
        $currentPrice = $priceService->priceForMappingPreview(
            $item,
            $mapping,
            FilterPriceCorrectionService::MODE_DISABLED,
            'USD',
        );

        if (! ($assay['applied'] ?? false)) {
            return sprintf(
                'Current price: $%s. Preview not available yet: %s.',
                number_format($currentPrice, 2),
                self::previewReason((string) ($assay['reason'] ?? 'missing_reference')),
            );
        }

        $previewPrice = $priceService->priceForMappingPreview(
            $item,
            $mapping,
            FilterPriceCorrectionService::MODE_WEIGHT_ONLY,
            'USD',
        );

        $delta = $previewPrice - $currentPrice;
        $deltaPercent = $currentPrice > 0
            ? ($delta / $currentPrice) * 100
            : 0.0;

        return sprintf(
            'Current price: $%s → Preview: $%s (%+.2f%%). Net catalyst weight after filter correction: %s kg. Saving will apply this manual filter mapping immediately.',
            number_format($currentPrice, 2),
            number_format($previewPrice, 2),
            $deltaPercent,
            number_format((float) $assay['weight_kg'], 3),
        );
    }

    private static function previewReason(string $reason): string
    {
        return match ($reason) {
            'missing_filter_weight' => 'the selected reference does not provide a usable filter weight',
            'invalid_net_weight' => 'the filter weight is equal to or greater than the product weight',
            'implausible_net_weight' => 'the resulting catalyst weight fails the safety check',
            default => 'more filter reference data is required',
        };
    }
}
