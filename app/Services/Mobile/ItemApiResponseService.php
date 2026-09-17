<?php

namespace App\Services\Mobile;

use App\Models\Item;
use Illuminate\Support\Collection;

class ItemApiResponseService
{
    public function __construct(
        private readonly ItemApiSettingsService $settings,
        private readonly ItemPriceService $itemPriceService,
    ) {}

    public function uniqueModeEnabled(): bool
    {
        return $this->settings->uniqueSerialItemsEnabled();
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return Collection<int, Item>
     */
    public function transform(Collection $items): Collection
    {
        if (! $this->uniqueModeEnabled() || $items->isEmpty()) {
            return $items;
        }

        return $this->applyAveragePrices($this->deduplicate($items));
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return Collection<int, string|int>
     */
    public function representativeIds(Collection $items): Collection
    {
        $seen = [];
        $ids = [];

        foreach ($items as $item) {
            $key = $this->serialKey($item);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $ids[] = $item->getKey();
        }

        return collect($ids);
    }

    /**
     * Compatibility entry point used by the paginated controller path.
     *
     * @param  Collection<int, Item>  $items
     * @return Collection<int, Item>
     */
    public function applyAverages(Collection $items): Collection
    {
        return $this->applyAveragePrices($items);
    }

    /**
     * Keep the representative item's stored assay fields unchanged and override
     * only its response price with the arithmetic mean of all calculable items
     * sharing the same normalized serial code.
     *
     * @param  Collection<int, Item>  $items
     * @return Collection<int, Item>
     */
    public function applyAveragePrices(Collection $items, ?string $currency = null): Collection
    {
        if ($items->isEmpty()) {
            return $items;
        }

        $serials = $items
            ->map(fn (Item $item): string => $this->normalizedSerial($item))
            ->filter(fn (string $serial): bool => $serial !== '')
            ->unique()
            ->values();

        if ($serials->isEmpty()) {
            return $items;
        }

        $currency ??= app()->bound('request')
            ? (string) request()->query('currency', 'USD')
            : 'USD';

        $siblings = Item::query()
            ->calculablePrice()
            ->whereIn('normalized_serial', $serials->all())
            ->get()
            ->groupBy(fn (Item $item): string => $this->normalizedSerial($item));

        foreach ($items as $item) {
            $serial = $this->normalizedSerial($item);

            if ($serial === '') {
                continue;
            }

            /** @var Collection<int, Item>|null $serialItems */
            $serialItems = $siblings->get($serial);

            if (! $serialItems instanceof Collection || $serialItems->isEmpty()) {
                continue;
            }

            $prices = $serialItems
                ->map(fn (Item $serialItem): float => $this->itemPriceService->priceFor($serialItem, $currency))
                ->filter(fn (float $price): bool => $price >= 0.0);

            if ($prices->isEmpty()) {
                continue;
            }

            $item->setAttribute('api_average_price', round((float) $prices->avg(), 2));
        }

        return $items;
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return Collection<int, Item>
     */
    private function deduplicate(Collection $items): Collection
    {
        $seen = [];

        return $items
            ->filter(function (Item $item) use (&$seen): bool {
                $key = $this->serialKey($item);

                if (isset($seen[$key])) {
                    return false;
                }

                $seen[$key] = true;

                return true;
            })
            ->values();
    }

    private function serialKey(Item $item): string
    {
        $serial = $this->normalizedSerial($item);

        return $serial !== '' ? $serial : '__item__'.(string) $item->getKey();
    }

    private function normalizedSerial(Item $item): string
    {
        $serial = trim((string) ($item->normalized_serial ?? ''));

        return $serial !== ''
            ? $serial
            : Item::normalizeSerialValue($item->serial_code);
    }
}
