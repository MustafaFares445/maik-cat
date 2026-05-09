<?php

namespace App\Enums;

enum PreferredLanguage: string
{
    case EN = 'en';
    case AR = 'ar';
    case HU = 'hu';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $language): string => $language->value,
            self::cases(),
        );
    }
}
