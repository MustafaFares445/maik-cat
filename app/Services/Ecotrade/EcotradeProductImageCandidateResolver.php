<?php

namespace App\Services\Ecotrade;

use App\Data\EcotradeProductData;
use App\Data\EcotradeProductImageCandidate;
use App\Models\Item;

class EcotradeProductImageCandidateResolver
{
    public function __construct(private readonly EcotradeRecordNormalizer $normalizer) {}

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<int, string>  $completedItemIds
     * @param  array<int, string>  $completedSourceHashes
     * @return array{
     *     summary: array<string, int>,
     *     candidates: list<EcotradeProductImageCandidate>
     * }
     */
    public function resolve(
        array $records,
        bool $replaceExisting = false,
        ?int $limit = null,
        array $completedItemIds = [],
        array $completedSourceHashes = [],
    ): array
    {
        $summary = [
            'records_total' => count($records),
            'records_valid' => 0,
            'records_invalid' => 0,
            'records_with_main_image' => 0,
            'records_without_main_image' => 0,
            'matched_items' => 0,
            'priceable_items' => 0,
            'skipped_checkpointed' => 0,
            'skipped_not_priceable' => 0,
            'skipped_existing_image' => 0,
            'candidates_available' => 0,
            'candidates_selected' => 0,
        ];

        /** @var array<string, EcotradeProductData> $productsByHash */
        $productsByHash = [];
        /** @var array<string, EcotradeProductData> $productsByUrl */
        $productsByUrl = [];

        foreach ($records as $record) {
            $product = $this->normalizer->normalize($record);

            if (! $product->isValid()) {
                $summary['records_invalid']++;

                continue;
            }

            $summary['records_valid']++;

            if (! is_string($product->mainImageUrl) || trim($product->mainImageUrl) === '') {
                $summary['records_without_main_image']++;

                continue;
            }

            $summary['records_with_main_image']++;
            $productsByHash[$product->sourceHash] ??= $product;
            $productsByUrl[$product->productUrl] ??= $product;
        }

        $candidates = [];
        $completedItemIds = array_fill_keys(array_filter(array_map('strval', $completedItemIds)), true);
        $completedSourceHashes = array_fill_keys(array_filter(array_map('strval', $completedSourceHashes)), true);

        foreach (array_chunk(array_values($productsByHash), 1000) as $productChunk) {
            $hashChunk = array_values(array_filter(array_map(
                fn (EcotradeProductData $product): ?string => $product->sourceHash,
                $productChunk
            )));

            $urlChunk = array_values(array_filter(array_map(
                fn (EcotradeProductData $product): ?string => $product->productUrl,
                $productChunk
            )));

            $items = Item::query()
                ->with('media')
                ->where('source', 'ecotrade')
                ->where(function ($query) use ($hashChunk, $urlChunk): void {
                    $query->whereIn('source_hash', $hashChunk)
                        ->orWhereIn('source_url', $urlChunk);
                })
                ->get();

            foreach ($items as $item) {
                $product = null;

                if (is_string($item->source_url) && isset($productsByUrl[$item->source_url])) {
                    $product = $productsByUrl[$item->source_url];
                } elseif (is_string($item->source_hash) && isset($productsByHash[$item->source_hash])) {
                    $product = $productsByHash[$item->source_hash];
                }

                if (! $product instanceof EcotradeProductData || ! is_string($product->mainImageUrl)) {
                    continue;
                }

                $summary['matched_items']++;

                $itemId = (string) $item->id;
                $sourceHash = is_string($item->source_hash) ? $item->source_hash : null;

                if (isset($completedItemIds[$itemId]) || ($sourceHash !== null && isset($completedSourceHashes[$sourceHash]))) {
                    $summary['skipped_checkpointed']++;

                    continue;
                }

                if (! $this->isPriceable($item)) {
                    $summary['skipped_not_priceable']++;

                    continue;
                }

                $summary['priceable_items']++;

                if (! $replaceExisting && $item->getFirstMedia('images')) {
                    $summary['skipped_existing_image']++;

                    continue;
                }

                $summary['candidates_available']++;

                if ($limit !== null && count($candidates) >= $limit) {
                    continue;
                }

                $candidates[] = new EcotradeProductImageCandidate($item, $product, $product->mainImageUrl);
                $summary['candidates_selected']++;
            }
        }

        return [
            'summary' => $summary,
            'candidates' => $candidates,
        ];
    }

    private function isPriceable(Item $item): bool
    {
        return (float) $item->weight_kg > 0
            && (
                (float) $item->pt_ppm > 0
                || (float) $item->pd_ppm > 0
                || (float) $item->rh_ppm > 0
            );
    }
}
