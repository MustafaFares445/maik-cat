<?php

namespace App\Services\Pricing;

use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Services\Mobile\ItemPriceService;
use App\Services\Mobile\ItemPriceSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FilterCandidateDetectionService
{
    private const array TARGET_GROUPS = ['BMW', 'MERCEDES', 'PSA'];

    public function __construct(
        private readonly ItemPriceService $itemPriceService,
        private readonly ItemPriceSettingsService $settingsService,
    ) {}

    /** @return array{scanned:int,candidates:int,matched:int,needs_review:int,skipped:int} */
    public function scan(): array
    {
        $settings = $this->settingsService->pricingConfiguration();
        $summary = ['scanned' => 0, 'candidates' => 0, 'matched' => 0, 'needs_review' => 0, 'skipped' => 0];

        Item::query()
            ->with('carGroup')
            ->whereHas('carGroup', fn (Builder $query) => $query->whereIn('name', self::TARGET_GROUPS))
            ->where('weight_kg', '>', 0)
            ->orderBy('id')
            ->chunkById(250, function (Collection $items) use (&$summary, $settings): void {
                foreach ($items as $item) {
                    $summary['scanned']++;

                    if (! $item instanceof Item || $this->isAlreadyReviewed($item) || $this->looksLikeFilterOnly($item)) {
                        $summary['skipped']++;

                        continue;
                    }

                    $evidence = $this->evidence($item, $settings);
                    if (! $evidence['candidate']) {
                        $summary['skipped']++;

                        continue;
                    }

                    $summary['candidates']++;
                    $filter = $this->resolveFilter($item, $evidence['filter_serial']);
                    $matched = $filter instanceof Item;
                    $status = $matched ? ItemFilterMapping::STATUS_DETECTED : ItemFilterMapping::STATUS_NEEDS_REVIEW;

                    ItemFilterMapping::query()->updateOrCreate(
                        ['item_id' => $item->getKey()],
                        [
                            'filter_item_id' => $filter?->getKey(),
                            'filter_serial' => $evidence['filter_serial'] ?? $filter?->serial_code,
                            'detection_method' => $evidence['method'],
                            'confidence' => $matched ? $evidence['confidence'] : 'medium',
                            'status' => $status,
                            'evidence' => $evidence,
                        ],
                    );

                    $matched ? $summary['matched']++ : $summary['needs_review']++;
                }
            });

        return $summary;
    }

    public function clearUnreviewed(): int
    {
        return ItemFilterMapping::query()
            ->whereIn('status', [ItemFilterMapping::STATUS_DETECTED, ItemFilterMapping::STATUS_NEEDS_REVIEW])
            ->delete();
    }

    private function isAlreadyReviewed(Item $item): bool
    {
        return ItemFilterMapping::query()
            ->where('item_id', $item->getKey())
            ->whereIn('status', [ItemFilterMapping::STATUS_APPROVED, ItemFilterMapping::STATUS_IGNORED])
            ->exists();
    }

    /** @param array<string,mixed> $settings @return array{candidate:bool,method:string,confidence:string,filter_serial:?string,explicit:bool,threshold_match:bool,price:float,weight:float,text:string} */
    private function evidence(Item $item, array $settings): array
    {
        $text = mb_strtoupper(implode(' ', [
            (string) $item->serial_code,
            (string) $item->model,
            (string) $item->details,
            (string) $item->shape_code,
        ]));

        $filterSerial = $this->extractFilterSerial($text);
        $combined = preg_match('/FILTER\s*\+\s*KAT|KAT\s*\+\s*FILTER|FILTRAS\s*\+\s*(KERAMIKA|METALAS)|CERAMIC\s*\+\s*DPF|SU\s+FILTRU/u', $text) === 1;
        $sameSerialFilter = $this->sameSerialFilterSibling($item);
        $explicit = $filterSerial !== null || $combined;

        $currentPrice = $this->itemPriceService->priceForFilterMode(
            $item,
            FilterPriceCorrectionService::MODE_DISABLED,
            'USD',
        );
        $thresholdMatch = (float) $item->weight_kg >= (float) $settings['filter_candidate_weight_threshold_kg']
            && $currentPrice >= (float) $settings['filter_candidate_price_threshold'];

        $candidate = $explicit || $thresholdMatch;
        $method = 'threshold_only';
        $confidence = 'low';

        if ($filterSerial !== null) {
            $method = 'explicit_filter_serial';
            $confidence = 'high';
        } elseif ($combined) {
            $method = 'combined_filter_description';
            $confidence = 'medium';
        } elseif ($thresholdMatch && $sameSerialFilter instanceof Item) {
            $method = 'threshold_with_same_serial_filter';
            $confidence = 'medium';
        }

        return [
            'candidate' => $candidate,
            'method' => $method,
            'confidence' => $confidence,
            'filter_serial' => $filterSerial,
            'explicit' => $explicit,
            'threshold_match' => $thresholdMatch,
            'price' => $currentPrice,
            'weight' => (float) $item->weight_kg,
            'text' => mb_substr($text, 0, 1000),
        ];
    }

    private function extractFilterSerial(string $text): ?string
    {
        if (preg_match('/\b(PF\s*[-.]?\s*\d{3,})\b/u', $text, $matches) !== 1) {
            return null;
        }

        return preg_replace('/\s+|[-.]/u', '', $matches[1]) ?: null;
    }

    private function resolveFilter(Item $item, ?string $filterSerial): ?Item
    {
        if ($filterSerial !== null) {
            $normalized = Item::normalizeSerialValue($filterSerial);
            $match = Item::query()
                ->where('normalized_serial', $normalized)
                ->where('weight_kg', '>', 0)
                ->orderBy('weight_kg')
                ->first();

            if ($match instanceof Item) {
                return $match;
            }
        }

        return $this->sameSerialFilterSibling($item);
    }

    private function sameSerialFilterSibling(Item $item): ?Item
    {
        $serial = Item::normalizeSerialValue($item->normalized_serial ?: $item->serial_code);
        if ($serial === '') {
            return null;
        }

        return Item::query()
            ->where('normalized_serial', $serial)
            ->whereKeyNot($item->getKey())
            ->where('weight_kg', '>', 0)
            ->get()
            ->first(fn (Item $candidate): bool => $this->looksLikeFilterOnly($candidate));
    }

    private function looksLikeFilterOnly(Item $item): bool
    {
        $text = mb_strtoupper(implode(' ', [(string) $item->serial_code, (string) $item->details, (string) $item->model, (string) $item->shape_code]));
        $hasFilter = preg_match('/FILTER|FILTRAS|DPF|\bPF\s*\d+/u', $text) === 1;
        $combined = preg_match('/FILTER\s*\+\s*KAT|KAT\s*\+\s*FILTER|FILTRAS\s*\+\s*(KERAMIKA|METALAS)|CERAMIC\s*\+\s*DPF|SU\s+FILTRU/u', $text) === 1;
        $catalyst = preg_match('/KATALIST|CATALYST/u', $text) === 1;

        return $hasFilter && ! $combined && ! $catalyst;
    }
}
