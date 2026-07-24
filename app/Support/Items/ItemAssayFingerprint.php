<?php

namespace App\Support\Items;

use App\Models\Item;

class ItemAssayFingerprint
{
    public static function fromItem(Item $item): ?string
    {
        return self::make(
            $item->car_group_id,
            $item->normalized_serial ?: Item::normalizeSerialValue($item->serial_code),
            $item->weight_kg,
            $item->pt_ppm,
            $item->pd_ppm,
            $item->rh_ppm,
        );
    }

    public static function make(
        mixed $groupId,
        mixed $normalizedSerial,
        mixed $weightKg,
        mixed $ptPpm,
        mixed $pdPpm,
        mixed $rhPpm,
    ): ?string {
        $groupId = trim((string) $groupId);
        $normalizedSerial = Item::normalizeSerialValue($normalizedSerial);

        if ($groupId === '' || $normalizedSerial === '') {
            return null;
        }

        return hash('sha256', implode('|', [
            $groupId,
            $normalizedSerial,
            self::decimal($weightKg, 3),
            self::decimal($ptPpm, 4),
            self::decimal($pdPpm, 4),
            self::decimal($rhPpm, 4),
        ]));
    }

    private static function decimal(mixed $value, int $precision): string
    {
        if ($value === null || $value === '') {
            return 'null';
        }

        return number_format((float) $value, $precision, '.', '');
    }
}
