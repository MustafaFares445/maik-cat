<?php

namespace App\Services\Mobile;

use App\Models\Item;
use Illuminate\Support\Collection;

class ItemApiResponseService
{
    public function __construct(
        private readonly ItemApiSettingsService $settings,
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

        return $this->applyAverages($this->deduplicate($items));
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
     * @param  Collection<int, Item>  $items
     * @return Collection<int, Item>
     */
    public function applyAverages(Collection $items): Collection
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

        $averages = Item::query()
            ->selectRaw(
                'normalized_serial, AVG(weight_kg) as average_weight_kg, AVG(pt_ppm) as average_pt_ppm, AVG(pd_ppm) as average_pd_ppm, AVG(rh_ppm) as average_rh_ppm'
            )
            ->whereIn('normalized_serial', $serials->all())
            ->groupBy('normalized_serial')
            ->toBase()
            ->get()
            ->keyBy(fn (object $row): string => (string) $row->normalized_serial);

        foreach ($items as $item) {
            $serial = $this->normalizedSerial($item);

            if ($serial === '') {
                continue;
            }

            $average = $averages->get($serial);

            if (! is_object($average)) {
                continue;
            }

            $item->setAttribute('weight_kg', $this->nullableFloat($average->average_weight_kg ?? null));
            $item->setAttribute('pt_ppm', $this->nullableFloat($average->average_pt_ppm ?? null));
            $item->setAttribute('pd_ppm', $this->nullableFloat($average->average_pd_ppm ?? null));
            $item->setAttribute('rh_ppm', $this->nullableFloat($average->average_rh_ppm ?? null));
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

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
