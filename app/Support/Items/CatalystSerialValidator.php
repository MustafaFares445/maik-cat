<?php

namespace App\Support\Items;

use Illuminate\Support\Str;

class CatalystSerialValidator
{
    public static function isUsable(mixed $serial): bool
    {
        if (! is_string($serial) && ! is_numeric($serial)) {
            return false;
        }

        $serial = trim((string) $serial);

        if ($serial === '') {
            return false;
        }

        $upper = Str::upper($serial);

        if (str_contains($upper, 'KONTROLINIS')) {
            return false;
        }

        if (in_array($upper, ['UNKNOWN', 'N/A', 'NA', 'NONE', 'NULL'], true)) {
            return false;
        }

        return preg_match('/^[?.\/_\\\\-]+$/u', $serial) !== 1;
    }
}
