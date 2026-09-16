<?php

namespace App\Services\Pricing;

use App\Models\Item;
use App\Models\ItemFilterMapping;
use Illuminate\Support\Collection;

class FilterPriceCorrectionService
{
    public const string MODE_DISABLED = 'disabled';
    public const string MODE_WEIGHT_ONLY = 'weight_only';
    public const string MODE_WEIGHT_AND_METALS = 'weight_and_metals';

    /** @return array{weight_kg:float,pt_ppm:float,pd_ppm:float,rh_ppm:float,applied:bool,applied_mode:string,filter_weight_kg:?float,mapping_id:?string,reason:?string} */
    public function effectiveAssay(Item $item, string $mode): array
    {
        $mode = $this->normalizeMode($mode);
        if ($mode === self::MODE_DISABLED) {
            return $this->unchanged($item, 'disabled');
        }

        $mapping = ItemFilterMapping::query()
            ->where('item_id', $item->getKey())
            ->where('status', ItemFilterMapping::STATUS_APPROVED)
            ->first();

        if (! $mapping instanceof ItemFilterMapping) {
            return $this->unchanged($item, 'no_approved_mapping');
        }

        return $this->effectiveAssayForMapping($item, $mapping, $mode);
    }

    /** @return array{weight_kg:float,pt_ppm:float,pd_ppm:float,rh_ppm:float,applied:bool,applied_mode:string,filter_weight_kg:?float,mapping_id:?string,reason:?string} */
    public function effectiveAssayForMapping(Item $item, ItemFilterMapping $mapping, string $mode): array
    {
        $mode = $this->normalizeMode($mode);
        if ($mode === self::MODE_DISABLED) {
            return $this->unchanged($item, 'disabled', $mapping);
        }

        $original = $this->originalAssay($item);
        $profile = $this->filterProfile($item, $mapping);
        $filterWeight = $mapping->filter_weight_override ?? $profile['weight_kg'];

        if ($filterWeight === null || $filterWeight <= 0.0) {
            return $this->unchanged($item, 'missing_filter_weight', $mapping);
        }

        $netWeight = round($original['weight_kg'] - $filterWeight, 6);
        if ($netWeight <= 0.0) {
            return $this->unchanged($item, 'invalid_net_weight', $mapping, $filterWeight);
        }

        if ($mode === self::MODE_WEIGHT_ONLY) {
            return $this->corrected(
                $mapping,
                self::MODE_WEIGHT_ONLY,
                $netWeight,
                $original['pt_ppm'],
                $original['pd_ppm'],
                $original['rh_ppm'],
                $filterWeight,
            );
        }

        if (! $profile['has_metal_profile']) {
            return $this->corrected(
                $mapping,
                self::MODE_WEIGHT_ONLY,
                $netWeight,
                $original['pt_ppm'],
                $original['pd_ppm'],
                $original['rh_ppm'],
                $filterWeight,
                'metal_profile_unavailable_fell_back_to_weight_only',
            );
        }

        return $this->corrected(
            $mapping,
            self::MODE_WEIGHT_AND_METALS,
            $netWeight,
            $this->netPpm($original['weight_kg'], $original['pt_ppm'], $netWeight, $profile['pt_grams']),
            $this->netPpm($original['weight_kg'], $original['pd_ppm'], $netWeight, $profile['pd_grams']),
            $this->netPpm($original['weight_kg'], $original['rh_ppm'], $netWeight, $profile['rh_grams']),
            $filterWeight,
        );
    }

    /** @return array{weight_kg:?float,pt_grams:float,pd_grams:float,rh_grams:float,has_metal_profile:bool,sample_count:int} */
    public function filterProfile(Item $item, ItemFilterMapping $mapping): array
    {
        $samples = $this->filterSamples($item, $mapping);
        $weights = $samples->pluck('weight_kg')
            ->filter(fn ($value): bool => is_numeric($value) && (float) $value > 0)
            ->map(fn ($value): float => (float) $value)
            ->values()
            ->all();

        $metalRows = $samples->filter(
            fn (Item $sample): bool => (float) $sample->weight_kg > 0
                && ((float) $sample->pt_ppm > 0 || (float) $sample->pd_ppm > 0 || (float) $sample->rh_ppm > 0),
        );

        return [
            'weight_kg' => $this->median($weights),
            'pt_grams' => $this->median($metalRows->map(fn (Item $sample): float => $this->metalGrams($sample, 'pt_ppm'))->all()) ?? 0.0,
            'pd_grams' => $this->median($metalRows->map(fn (Item $sample): float => $this->metalGrams($sample, 'pd_ppm'))->all()) ?? 0.0,
            'rh_grams' => $this->median($metalRows->map(fn (Item $sample): float => $this->metalGrams($sample, 'rh_ppm'))->all()) ?? 0.0,
            'has_metal_profile' => $metalRows->isNotEmpty(),
            'sample_count' => $samples->count(),
        ];
    }

    /** @return Collection<int,Item> */
    private function filterSamples(Item $item, ItemFilterMapping $mapping): Collection
    {
        $filterSerial = Item::normalizeSerialValue($mapping->filter_serial);
        $targetSerial = Item::normalizeSerialValue($item->normalized_serial ?: $item->serial_code);

        if ($filterSerial !== '') {
            $samples = Item::query()
                ->where('normalized_serial', $filterSerial)
                ->where('weight_kg', '>', 0)
                ->get();

            if ($filterSerial === $targetSerial) {
                $samples = $samples
                    ->filter(fn (Item $sample): bool => $this->looksLikeFilterOnly($sample))
                    ->values();
            }

            if ($samples->isNotEmpty()) {
                return $samples;
            }
        }

        if ($mapping->filter_item_id !== null) {
            $filterItem = Item::query()->find($mapping->filter_item_id);
            if ($filterItem instanceof Item) {
                return collect([$filterItem]);
            }
        }

        return collect();
    }

    private function looksLikeFilterOnly(Item $item): bool
    {
        $text = mb_strtoupper(implode(' ', [(string) $item->details, (string) $item->model, (string) $item->shape_code]));
        $hasFilter = preg_match('/FILTER|FILTRAS|DPF|\bPF\s*\d+/u', $text) === 1;
        $isCombined = preg_match('/FILTER\s*\+\s*KAT|KAT\s*\+\s*FILTER|FILTRAS\s*\+\s*(KERAMIKA|METALAS)|CERAMIC\s*\+\s*DPF|SU\s+FILTRU/u', $text) === 1;
        $isCatalyst = preg_match('/KATALIST|CATALYST/u', $text) === 1;

        return $hasFilter && ! $isCombined && ! $isCatalyst;
    }

    /** @return array{weight_kg:float,pt_ppm:float,pd_ppm:float,rh_ppm:float} */
    private function originalAssay(Item $item): array
    {
        return [
            'weight_kg' => max((float) $item->weight_kg, 0.0),
            'pt_ppm' => max((float) $item->pt_ppm, 0.0),
            'pd_ppm' => max((float) $item->pd_ppm, 0.0),
            'rh_ppm' => max((float) $item->rh_ppm, 0.0),
        ];
    }

    /** @return array{weight_kg:float,pt_ppm:float,pd_ppm:float,rh_ppm:float,applied:bool,applied_mode:string,filter_weight_kg:?float,mapping_id:?string,reason:?string} */
    private function unchanged(Item $item, string $reason, ?ItemFilterMapping $mapping = null, ?float $filterWeight = null): array
    {
        return [
            ...$this->originalAssay($item),
            'applied' => false,
            'applied_mode' => self::MODE_DISABLED,
            'filter_weight_kg' => $filterWeight,
            'mapping_id' => $mapping ? (string) $mapping->getKey() : null,
            'reason' => $reason,
        ];
    }

    /** @return array{weight_kg:float,pt_ppm:float,pd_ppm:float,rh_ppm:float,applied:bool,applied_mode:string,filter_weight_kg:?float,mapping_id:?string,reason:?string} */
    private function corrected(ItemFilterMapping $mapping, string $mode, float $weight, float $pt, float $pd, float $rh, float $filterWeight, ?string $reason = null): array
    {
        return [
            'weight_kg' => $weight,
            'pt_ppm' => max($pt, 0.0),
            'pd_ppm' => max($pd, 0.0),
            'rh_ppm' => max($rh, 0.0),
            'applied' => true,
            'applied_mode' => $mode,
            'filter_weight_kg' => $filterWeight,
            'mapping_id' => (string) $mapping->getKey(),
            'reason' => $reason,
        ];
    }

    private function metalGrams(Item $item, string $ppmField): float
    {
        return max((float) $item->weight_kg, 0.0) * max((float) $item->{$ppmField}, 0.0) / 1000;
    }

    private function netPpm(float $grossWeightKg, float $grossPpm, float $netWeightKg, float $filterMetalGrams): float
    {
        $grossGrams = $grossWeightKg * $grossPpm / 1000;
        $netGrams = max($grossGrams - $filterMetalGrams, 0.0);

        return round(($netGrams * 1000) / $netWeightKg, 6);
    }

    /** @param array<int,float|int> $values */
    private function median(array $values): ?float
    {
        $values = array_values(array_filter(
            array_map('floatval', $values),
            fn (float $value): bool => is_finite($value) && $value >= 0,
        ));

        if ($values === []) {
            return null;
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function normalizeMode(string $mode): string
    {
        return in_array($mode, [self::MODE_DISABLED, self::MODE_WEIGHT_ONLY, self::MODE_WEIGHT_AND_METALS], true)
            ? $mode
            : self::MODE_DISABLED;
    }
}
