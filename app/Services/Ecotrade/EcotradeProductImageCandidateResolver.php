<?php

namespace App\Services\Ecotrade;

use App\Data\EcotradeProductData;
use App\Data\EcotradeProductImageCandidate;
use App\Models\CarGroup;
use App\Models\Item;
use App\Services\ImportSheetGroupResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EcotradeProductImageCandidateResolver
{
    /** @var array<string,string>|null */
    private ?array $groupIdsCache = null;

    public function __construct(
        private readonly EcotradeRecordNormalizer $normalizer,
        private readonly ImportSheetGroupResolver $groupResolver,
    ) {}

    /**
     * @param array<int,array<string,mixed>> $records
     * @param array<string,mixed> $options
     * @return array{summary:array<string,int>,candidates:list<EcotradeProductImageCandidate>,copy_sources:list<Item>}
     */
    public function resolve(array $records, array $options = []): array
    {
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
            ? $this->lookupSet((array) $options['allowed_item_ids'])
            : null;
        $groupIds = $this->groupIdsByCanonicalName();

        $summary = [
            'records_total' => count($records),
            'records_valid' => 0,
            'records_invalid' => 0,
            'records_with_main_image' => 0,
            'records_without_main_image' => 0,
            'records_rejected_placeholder_image' => 0,
            'families_available' => 0,
            'families_ambiguous' => 0,
            'families_without_group' => 0,
            'families_without_items' => 0,
            'matched_items' => 0,
            'priceable_items' => 0,
            'skipped_not_in_media_report' => 0,
            'skipped_checkpointed' => 0,
            'skipped_not_failed_checkpoint' => 0,
            'skipped_not_priceable' => 0,
            'skipped_existing_image' => 0,
            'copy_sources_available' => 0,
            'candidates_available' => 0,
            'candidates_selected' => 0,
        ];

        /** @var array<string,list<EcotradeProductData>> $productsByNamedFamily */
        $productsByNamedFamily = [];

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

            if ($this->isRejectedImageUrl($product->mainImageUrl)) {
                $summary['records_rejected_placeholder_image']++;
                continue;
            }

            $groupName = $this->canonicalGroupFor($product, $groupIds);
            $serial = Item::normalizeSerialValue($product->serialCode);

            if ($groupName === '' || $serial === '') {
                $summary['records_invalid']++;
                continue;
            }

            $productsByNamedFamily[$groupName.'|'.$serial][] = $product;
        }

        /** @var array<string,EcotradeProductData> $productsByFamily */
        $productsByFamily = [];
        /** @var array<string,list<string>> $serialsByGroup */
        $serialsByGroup = [];

        foreach ($productsByNamedFamily as $namedFamily => $products) {
            [$groupName, $serial] = explode('|', $namedFamily, 2);
            $groupId = $groupIds[$groupName] ?? null;

            if (! is_string($groupId) || $groupId === '') {
                $summary['families_without_group']++;
                continue;
            }

            $uniqueUrls = collect($products)
                ->pluck('productUrl')
                ->filter()
                ->unique()
                ->count();

            if ($uniqueUrls > 1) {
                $summary['families_ambiguous']++;
            }

            usort($products, static function (EcotradeProductData $left, EcotradeProductData $right): int {
                $imageCount = $right->imageCount <=> $left->imageCount;

                if ($imageCount !== 0) {
                    return $imageCount;
                }

                return strcmp($left->productUrl, $right->productUrl);
            });

            $familyKey = $groupId.'|'.$serial;
            $productsByFamily[$familyKey] = $products[0];
            $serialsByGroup[$groupId][] = $serial;
        }

        $summary['families_available'] = count($productsByFamily);

        /** @var array<string,Collection<int,Item>> $itemsByFamily */
        $itemsByFamily = [];

        foreach ($serialsByGroup as $groupId => $serials) {
            foreach (array_chunk(array_values(array_unique($serials)), 1000) as $serialChunk) {
                Item::query()
                    ->with('media')
                    ->where('car_group_id', $groupId)
                    ->whereIn('normalized_serial', $serialChunk)
                    ->get()
                    ->each(function (Item $item) use (&$itemsByFamily): void {
                        $familyKey = $item->car_group_id.'|'.Item::normalizeSerialValue(
                            $item->normalized_serial ?: $item->serial_code,
                        );

                        $itemsByFamily[$familyKey] ??= collect();
                        $itemsByFamily[$familyKey]->push($item);
                    });
            }
        }

        $candidates = [];
        $copySources = [];

        foreach ($productsByFamily as $familyKey => $product) {
            $items = ($itemsByFamily[$familyKey] ?? collect())->unique('id')->values();

            if ($items->isEmpty()) {
                $summary['families_without_items']++;
                continue;
            }

            $summary['matched_items'] += $items->count();

            if ($allowedItemIds !== null) {
                $eligibleItems = $items->filter(
                    static fn (Item $item): bool => isset($allowedItemIds[(string) $item->id]),
                );

                if ($eligibleItems->isEmpty()) {
                    $summary['skipped_not_in_media_report'] += $items->count();
                    continue;
                }
            } else {
                $eligibleItems = $items;
            }

            $priceableItems = $eligibleItems->filter(fn (Item $item): bool => $this->isPriceable($item));
            $summary['priceable_items'] += $priceableItems->count();
            $summary['skipped_not_priceable'] += $eligibleItems->count() - $priceableItems->count();

            if ($priceableItems->isEmpty()) {
                continue;
            }

            $existingImageSource = $items->first(
                static fn (Item $item): bool => $item->getFirstMedia('images') !== null,
            );

            if ($existingImageSource instanceof Item && ! $replaceExisting) {
                $copySources[(string) $existingImageSource->id] = $existingImageSource;
                $summary['skipped_existing_image']++;
                continue;
            }

            /** @var Item|null $target */
            $target = $priceableItems
                ->sortBy(static fn (Item $item): string => (string) $item->created_at.'|'.$item->id)
                ->first();

            if (! $target instanceof Item) {
                continue;
            }

            $itemId = (string) $target->id;
            $sourceHash = is_string($target->source_hash) ? $target->source_hash : null;

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

            $summary['candidates_available']++;

            if ($limit !== null && count($candidates) >= $limit) {
                continue;
            }

            $candidates[] = new EcotradeProductImageCandidate(
                $target,
                $product,
                (string) $product->mainImageUrl,
            );
            $summary['candidates_selected']++;
        }

        $summary['copy_sources_available'] = count($copySources);

        return [
            'summary' => $summary,
            'candidates' => $candidates,
            'copy_sources' => array_values($copySources),
        ];
    }

    /** @return array<string,true> */
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

    /** @param array<string,string> $groupIds */
    private function canonicalGroupFor(EcotradeProductData $product, array $groupIds): string
    {
        $slug = Str::of($product->brandSlug)
            ->trim()
            ->lower()
            ->replace('_', '-')
            ->replace(' ', '-')
            ->toString();

        $configured = (array) config('imports.ecotrade_brand_groups', []);
        $configuredGroup = $this->groupResolver->canonicalSheetName(
            $this->groupResolver->normalizeSheetName((string) ($configured[$slug] ?? $product->brandName)),
        );
        $brandGroup = $this->groupResolver->canonicalSheetName(
            $this->groupResolver->normalizeSheetName($product->brandName),
        );

        if (isset($groupIds[$configuredGroup])) {
            return $configuredGroup;
        }

        if (isset($groupIds[$brandGroup])) {
            return $brandGroup;
        }

        return $configuredGroup;
    }

    /** @return array<string,string> */
    private function groupIdsByCanonicalName(): array
    {
        if ($this->groupIdsCache !== null) {
            return $this->groupIdsCache;
        }

        $groups = [];

        CarGroup::query()
            ->get(['id', 'name', 'excel_sheet_name'])
            ->each(function (CarGroup $group) use (&$groups): void {
                foreach ([$group->name, $group->excel_sheet_name] as $name) {
                    if (! is_string($name) || trim($name) === '') {
                        continue;
                    }

                    $canonical = $this->groupResolver->canonicalSheetName(
                        $this->groupResolver->normalizeSheetName($name),
                    );
                    $groups[$canonical] = (string) $group->id;
                }
            });

        return $this->groupIdsCache = $groups;
    }

    private function isRejectedImageUrl(string $url): bool
    {
        $url = Str::lower(trim($url));

        foreach ((array) config('imports.rejected_image_url_fragments', []) as $fragment) {
            if ($fragment !== '' && str_contains($url, Str::lower((string) $fragment))) {
                return true;
            }
        }

        return ! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://');
    }
}
