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
     * @param  array{
     *     replace_existing?: bool,
     *     limit?: int|null,
     *     completed_item_ids?: array<int, string>,
     *     completed_source_hashes?: array<int, string>,
     *     failed_item_ids?: array<int, string>,
     *     failed_source_hashes?: array<int, string>,
     *     failed_checkpoint_available?: bool,
     *     retry_incomplete_only?: bool,
     *     allowed_item_ids?: array<int, string>
     * }  $options
     * @return array{
     *     summary: array<string, int>,
     *     candidates: list<EcotradeProductImageCandidate>
     * }
     */
    public function resolve(
        array $records,
        array $options = [],
    ): array {
        $replaceExisting = (bool) ($options['replace_existing'] ?? false);
        $limit = isset($options['limit']) && is_numeric($options['limit'])
            ? max(1, (int) $options['limit'])
            : null;
        $completedItemIds = $this->lookupSet($options['completed_item_ids'] ?? []);
        $completedSourceHashes = $this->lookupSet($options['completed_source_hashes'] ?? []);
        $failedItemIds = $this->lookupSet($options['failed_item_ids'] ?? []);
        $failedSourceHashes = $this->lookupSet($options['failed_source_hashes'] ?? []);
        $failedCheckpointAvailable = (bool) ($options['failed_checkpoint_available'] ?? false);
        $retryIncompleteOnly = (bool) ($options['retry_incomplete_only'] ?? false);
        $allowedItemIds = array_key_exists('allowed_item_ids', $options)
            ? $this->lookupSet($options['allowed_item_ids'])
            : null;
        $allowedItemIdValues = $allowedItemIds !== null ? array_keys($allowedItemIds) : null;
        $allowedSources = $this->allowedItemSources($allowedItemIdValues);

        $summary = [
            'records_total' => count($records),
            'records_valid' => 0,
            'records_invalid' => 0,
            'records_with_main_image' => 0,
            'records_without_main_image' => 0,
            'matched_items' => 0,
            'priceable_items' => 0,
            'skipped_not_in_media_report' => 0,
            'skipped_checkpointed' => 0,
            'skipped_not_failed_checkpoint' => 0,
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

            if (
                $allowedItemIdValues !== null
                && ! isset($allowedSources['hashes'][$product->sourceHash])
                && ! isset($allowedSources['urls'][$product->productUrl])
            ) {
                continue;
            }

            $productsByHash[$product->sourceHash] ??= $product;
            $productsByUrl[$product->productUrl] ??= $product;
        }

        $candidates = [];

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
                ->when(
                    $allowedItemIdValues !== null,
                    static fn ($query) => $query->whereIn('id', $allowedItemIdValues),
                )
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

                if ($allowedItemIds !== null && ! isset($allowedItemIds[$itemId])) {
                    $summary['skipped_not_in_media_report']++;

                    continue;
                }

                if (isset($completedItemIds[$itemId]) || ($sourceHash !== null && isset($completedSourceHashes[$sourceHash]))) {
                    $summary['skipped_checkpointed']++;

                    continue;
                }

                if (
                    $retryIncompleteOnly
                    && $failedCheckpointAvailable
                    && ! isset($failedItemIds[$itemId])
                    && ($sourceHash === null || ! isset($failedSourceHashes[$sourceHash]))
                ) {
                    $summary['skipped_not_failed_checkpoint']++;

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

    /**
     * @param  array<int, string>  $values
     * @return array<string, true>
     */
    private function lookupSet(array $values): array
    {
        return array_fill_keys(array_filter(array_map('strval', $values)), true);
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

    /**
     * @param  list<string>|null  $itemIds
     * @return array{hashes: array<string, true>, urls: array<string, true>}
     */
    private function allowedItemSources(?array $itemIds): array
    {
        if ($itemIds === null) {
            return [
                'hashes' => [],
                'urls' => [],
            ];
        }

        $items = Item::query()
            ->whereIn('id', $itemIds)
            ->get(['source_hash', 'source_url']);

        return [
            'hashes' => $this->lookupSet($items
                ->pluck('source_hash')
                ->filter(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')
                ->map(static fn (mixed $value): string => (string) $value)
                ->all()),
            'urls' => $this->lookupSet($items
                ->pluck('source_url')
                ->filter(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')
                ->map(static fn (mixed $value): string => (string) $value)
                ->all()),
        ];
    }
}
