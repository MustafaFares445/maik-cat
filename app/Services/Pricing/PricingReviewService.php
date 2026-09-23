<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Services\Mobile\ItemPriceService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PricingReviewService
{
    public function __construct(
        private readonly FilterPriceCorrectionService $filterPriceCorrectionService,
        private readonly ItemPriceService $itemPriceService,
    ) {}

    /** @return EloquentCollection<int, ItemFilterMapping> */
    public function relatedReviewMappings(ItemFilterMapping $mapping): EloquentCollection
    {
        $mapping->loadMissing('item.carGroup');
        $item = $mapping->item;

        if (! $item instanceof Item) {
            return new EloquentCollection;
        }

        $normalizedSerial = Item::normalizeSerialValue($item->normalized_serial ?: $item->serial_code);

        return ItemFilterMapping::query()
            ->with(['item.carGroup', 'filterItem'])
            ->where('status', ItemFilterMapping::STATUS_NEEDS_REVIEW)
            ->whereHas('item', function ($query) use ($item, $normalizedSerial): void {
                $query->where('car_group_id', $item->car_group_id);

                if ($normalizedSerial !== '') {
                    $query->where('normalized_serial', $normalizedSerial);
                } else {
                    $query->whereKey($item->getKey());
                }
            })
            ->orderBy('id')
            ->get();
    }

    /** @return array{type:string,label:string,instruction:string,variant:?string} */
    public function issue(ItemFilterMapping $mapping): array
    {
        $evidence = is_array($mapping->evidence) ? $mapping->evidence : [];
        $review = is_array($evidence['pricing_review'] ?? null) ? $evidence['pricing_review'] : [];

        $type = (string) ($review['type'] ?? 'pricing_data');
        $label = match ($type) {
            'component_weight' => 'Component weight needs confirmation',
            'metallic_component' => 'Material details need confirmation',
            'assay_source' => 'Metal values or source need confirmation',
            default => $this->genericIssueLabel($mapping, $evidence),
        };

        return [
            'type' => $type,
            'label' => $label,
            'instruction' => (string) ($review['instruction'] ?? $mapping->notes ?? 'Check the item details before showing this item in the app again.'),
            'variant' => filled($review['variant'] ?? null) ? (string) $review['variant'] : null,
        ];
    }

    /** @param array<string, mixed> $evidence */
    private function genericIssueLabel(ItemFilterMapping $mapping, array $evidence): string
    {
        if ($mapping->filter_item_id === null && filled($mapping->filter_serial)) {
            return 'Matching filter item was not found';
        }

        if ($mapping->filter_item_id === null && (bool) ($evidence['explicit'] ?? false)) {
            return 'Filter details need confirmation';
        }

        if ($mapping->filter_item_id === null && (bool) ($evidence['threshold_match'] ?? false)) {
            return 'Weight and price need confirmation';
        }

        if ($mapping->item instanceof Item && $mapping->filter_item_id !== null) {
            $result = $this->filterPriceCorrectionService->effectiveAssayForMapping(
                $mapping->item,
                $mapping,
                FilterPriceCorrectionService::MODE_WEIGHT_ONLY,
            );

            return match ($result['reason'] ?? null) {
                'missing_filter_weight' => 'Filter weight is missing',
                'invalid_net_weight' => 'Filter weight is too high for this item',
                'implausible_net_weight' => 'Remaining item weight looks too low',
                default => 'Price details need confirmation',
            };
        }

        return 'Price details need confirmation';
    }

    /** @return array{all_safe:bool,count:int,rows:list<array<string,mixed>>} */
    public function previewFamilyWeight(ItemFilterMapping $mapping, float $filterWeightKg): array
    {
        $filterWeightKg = round($filterWeightKg, 6);
        $mappings = $this->relatedReviewMappings($mapping);
        $rows = [];

        foreach ($mappings as $related) {
            $item = $related->item;

            if (! $item instanceof Item) {
                continue;
            }

            $previewMapping = $related->replicate(['id', 'created_at', 'updated_at']);
            $previewMapping->setRelation('item', $item);
            $previewMapping->filter_weight_override = $filterWeightKg;

            $assay = $this->filterPriceCorrectionService->effectiveAssayForMapping(
                $item,
                $previewMapping,
                FilterPriceCorrectionService::MODE_WEIGHT_ONLY,
            );

            $currentPrice = $this->itemPriceService->priceFor($item, 'USD');
            $proposedPrice = ($assay['applied'] ?? false)
                ? $this->itemPriceService->priceForMappingPreview(
                    $item,
                    $previewMapping,
                    FilterPriceCorrectionService::MODE_WEIGHT_ONLY,
                    'USD',
                )
                : $currentPrice;

            $rows[] = [
                'mapping_id' => (string) $related->getKey(),
                'item_id' => (string) $item->getKey(),
                'serial' => (string) $item->serial_code,
                'model' => (string) $item->model,
                'original_weight_kg' => (float) $item->weight_kg,
                'filter_weight_kg' => $filterWeightKg,
                'net_weight_kg' => ($assay['applied'] ?? false) ? (float) $assay['weight_kg'] : null,
                'current_price_usd' => $currentPrice,
                'proposed_price_usd' => $proposedPrice,
                'delta_usd' => round($proposedPrice - $currentPrice, 2),
                'delta_percent' => $currentPrice > 0 ? round((($proposedPrice - $currentPrice) / $currentPrice) * 100, 2) : null,
                'safe' => (bool) ($assay['applied'] ?? false),
                'reason' => (string) ($assay['reason'] ?? ''),
            ];
        }

        return [
            'all_safe' => $rows !== [] && collect($rows)->every(fn (array $row): bool => $row['safe']),
            'count' => count($rows),
            'rows' => $rows,
        ];
    }

    /** @return array{applied:int,preview:array{all_safe:bool,count:int,rows:list<array<string,mixed>>}} */
    public function applyFamilyWeight(ItemFilterMapping $mapping, float $filterWeightKg, int|string|null $approvedBy, ?string $note = null): array
    {
        $preview = $this->previewFamilyWeight($mapping, $filterWeightKg);

        if (! $preview['all_safe']) {
            throw new RuntimeException('The entered component weight is not safe for every linked item. Review the preview and weight value.');
        }

        $mappingIds = collect($preview['rows'])->pluck('mapping_id')->all();

        DB::transaction(function () use ($mappingIds, $filterWeightKg, $approvedBy, $note, $preview): void {
            $records = ItemFilterMapping::query()
                ->whereKey($mappingIds)
                ->where('status', ItemFilterMapping::STATUS_NEEDS_REVIEW)
                ->lockForUpdate()
                ->get();

            if ($records->count() !== count($mappingIds)) {
                throw new RuntimeException('The review family changed while the preview was open. Refresh and try again.');
            }

            foreach ($records as $record) {
                $evidence = is_array($record->evidence) ? $record->evidence : [];
                $evidence['manual_review_resolution'] = [
                    'type' => 'component_weight',
                    'filter_weight_kg' => round($filterWeightKg, 6),
                    'affected_mapping_ids' => $mappingIds,
                    'preview' => collect($preview['rows'])
                        ->firstWhere('mapping_id', (string) $record->getKey()),
                    'approved_by' => $approvedBy,
                    'approved_at' => now()->toIso8601String(),
                    'note' => $note,
                ];

                $record->update([
                    'filter_weight_override' => $filterWeightKg,
                    'confidence' => 'manual',
                    'status' => ItemFilterMapping::STATUS_APPROVED,
                    'evidence' => $evidence,
                    'notes' => filled($note) ? $note : $record->notes,
                    'approved_by' => $approvedBy,
                    'approved_at' => now(),
                ]);
            }
        });

        return [
            'applied' => count($mappingIds),
            'preview' => $preview,
        ];
    }

    public function approveCurrentPricing(ItemFilterMapping $mapping, int|string|null $approvedBy, string $note): int
    {
        $mappings = $this->relatedReviewMappings($mapping);

        if ($mappings->isEmpty()) {
            throw new RuntimeException('No linked Needs Review items were found.');
        }

        DB::transaction(function () use ($mappings, $approvedBy, $note): void {
            foreach ($mappings as $record) {
                $evidence = is_array($record->evidence) ? $record->evidence : [];
                $evidence['manual_review_resolution'] = [
                    'type' => 'keep_current_pricing',
                    'approved_by' => $approvedBy,
                    'approved_at' => now()->toIso8601String(),
                    'note' => $note,
                ];

                $record->update([
                    'status' => ItemFilterMapping::STATUS_IGNORED,
                    'evidence' => $evidence,
                    'notes' => $note,
                    'approved_by' => null,
                    'approved_at' => null,
                ]);
            }
        });

        return $mappings->count();
    }
}
