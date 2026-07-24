<?php

namespace App\Services\Ecotrade;

use App\Data\EcotradeProductData;
use App\Data\EcotradeProductImageCandidate;
use App\Models\CarGroup;
use App\Models\Item;
use App\Services\ImportSheetGroupResolver;
use App\Support\Items\CatalystSerialValidator;
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

        $summary = $this->emptySummary(count($records));
        /** @var array<string,list<EcotradeProductData>> $productsByNamedFamily */
        $productsByNamedFamily = [];

        foreach ($records as $record) {
            $product = $this->normalizer->normalize($record);

            if (! $product->isValid() || ! CatalystSerialValidator::isUsable($product->serialCode)) {
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

        [$productsByFamily, $serialsByGroup] = $this->resolveProductFamilies(
            $productsByNamedFamily,
            $groupIds,
            $summary,
        );
        $summary['families_available'] = count($productsByFamily);
        $itemsByFamily = $this->loadItemsByFamily($serialsByGroup);
        $candidates = [];
        $copySources = [];

        foreach ($productsByFamily as $familyKey => $product) {
            /** @var Collection<int,Item> $items */
            $items = ($itemsByFamily[$familyKey] ?? collect())->unique('id')->values();

            if ($items->isEmpty()) {
                $summary['families_without_items']++;
                continue;
            }

            $primaryFamilies = $items
                ->map(static fn (Item $item): string => Item::normalizeSerialValue(
                    $item->normalized_serial ?: $item->serial_code,
                ))
                ->filter()
                ->unique();

            if ($primaryFamilies->count() > 1) {
                $summary['families_ambiguous']++;
                continue;
            }

            $summary['matched_items'] += $items->count();
            $eligibleItems = $this->eligibleItems($items, $allowedItemIds, $summary);

            if ($eligibleItems->isEmpty()) {
                continue;
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

            if (! $target instanceof Item || $this->isCheckpointed(
                $target,
                $completedItemIds,
                $completedSourceHashes,
                $failedItemIds,
                $failedSourceHashes,
                $failedCheckpointAvailable,
                $retryIncompleteOnly,
                $summary,
            )) {
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

    /** @return array<string,int> */
    private function emptySummary(int $recordsTotal): array
    {
        return [
            'records_total' => $recordsTotal,
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
    }

    /**
     * @param array<string,list<EcotradeProductData>> $productsByNamedFamily
     * @param array<string,string> $groupIds
     * @param array<string,int> $summary
     * @return array{0:array<string,EcotradeProductData>,1:array<string,list<string>>}
     */
    private function resolveProductFamilies(array $productsByNamedFamily, array $groupIds, array &$summary): array
    {
        $productsByFamily = [];
        $serialsByGroup = [];

        foreach ($productsByNamedFamily as $namedFamily => $products) {
            [$groupName, $serial] = explode('|', $namedFamily, 2);
            $groupId = $groupIds[$groupName] ?? null;

            if (! is_string($groupId) || $groupId === '') {
                $summary['families_without_group']++;
                continue;
            }

            $imageUrls = collect($products)
                ->pluck('mainImageUrl')
                ->filter()
                ->unique()
                ->values();

            if ($imageUrls->count() > 1) {
                $summary['families_ambiguous']++;
                continue;
            }

            usort($products, static function (EcotradeProductData $left, EcotradeProductData $right): int {
                $imageCount = $right->imageCount <=> $left->imageCount;

                return $imageCount !== 0 ? $imageCount : strcmp($left->productUrl, $right->productUrl);
            });

            $familyKey = $groupId.'|'.$serial;
            $productsByFamily[$familyKey] = $products[0];
            $serialsByGroup[$groupId][] = $serial;
        }

        return [$productsByFamily, $serialsByGroup];
    }

    /** @param array<string,list<string>> $serialsByGroup @return array<string,Collection<int,Item>> */
    private function loadItemsByFamily(array $serialsByGroup): array
    {
        $itemsByFamily = [];

        foreach ($serialsByGroup as $groupId => $serials) {
            $wanted = $this->lookupSet($serials);

            Item::query()
                ->with(['media', 'extraCodes'])
                ->where('car_group_id', $groupId)
                ->get()
                ->each(function (Item $item) use ($groupId, $wanted, &$itemsByFamily): void {
                    $codes = collect([
                        $item->normalized_serial ?: $item->serial_code,
                        ...$item->extraCodes->pluck('code')->all(),
                    ])
                        ->map(static fn (mixed $code): string => Item::normalizeSerialValue($code))
                        ->filter()
                        ->unique();

                    foreach ($codes as $code) {
                        if (! isset($wanted[$code])) {
                            continue;
                        }

                        $familyKey = $groupId.'|'.$code;
                        $itemsByFamily[$familyKey] ??= collect();
                        $itemsByFamily[$familyKey]->push($item);
                    }
                });
        }

        return $itemsByFamily;
    }

    /**
     * @param Collection<int,Item> $items
     * @param array<string,true>|null $allowedItemIds
     * @param array<string,int> $summary
     * @return Collection<int,Item>
     */
    private function eligibleItems(Collection $items, ?array $allowedItemIds, array &$summary): Collection
    {
        if ($allowedItemIds === null) {
            return $items;
        }

        $eligible = $items->filter(
            static fn (Item $item): bool => isset($allowedItemIds[(string) $item->id]),
        );

        if ($eligible->isEmpty()) {
            $summary['skipped_not_in_media_report'] += $items->count();
        }

        return $eligible;
    }

    /** @param array<string,true> $completedItemIds @param array<string,true> $completedSourceHashes @param array<string,true> $failedItemIds @param array<string,true> $failedSourceHashes @param array<string,int> $summary */
    private function isCheckpointed(
        Item $item,
        array $completedItemIds,
        array $completedSourceHashes,
        array $failedItemIds,
        array $failedSourceHashes,
        bool $failedCheckpointAvailable,
        bool $retryIncompleteOnly,
        array &$summary,
    ): bool {
        $itemId = (string) $item->id;
        $sourceHash = is_string($item->source_hash) ? $item->source_hash : null;

        if (isset($completedItemIds[$itemId]) || ($sourceHash !== null && isset($completedSourceHashes[$sourceHash]))) {
            $summary['skipped_checkpointed']++;
            return true;
        }

        if (
            $retryIncompleteOnly
            && $failedCheckpointAvailable
            && ! isset($failedItemIds[$itemId])
            && ($sourceHash === null || ! isset($failedSourceHashes[$sourceHash]))
        ) {
            $summary['skipped_not_failed_checkpoint']++;
            return true;
        }

        return false;
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
