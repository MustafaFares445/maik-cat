<?php

namespace App\Support\Items;

class LegacyItemWeightNormalizer
{
    private const float MAX_REASONABLE_ITEM_WEIGHT_KG = 50.0;

    private const float GRAMS_PER_KILOGRAM = 1000.0;

    public static function toKilograms(?float $weight): ?float
    {
        if ($weight === null) {
            return null;
        }

        if ($weight > self::MAX_REASONABLE_ITEM_WEIGHT_KG) {
            return $weight / self::GRAMS_PER_KILOGRAM;
        }

        return $weight;
    }
}
