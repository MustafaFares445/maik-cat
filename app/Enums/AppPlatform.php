<?php

namespace App\Enums;

final class AppPlatform
{
    public const IOS = 'ios';

    public const ANDROID = 'android';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [self::IOS, self::ANDROID];
    }

    public static function storeUrl(string $platform, string $storeId): string
    {
        return match ($platform) {
            self::ANDROID => "https://play.google.com/store/apps/details?id={$storeId}",
            self::IOS => "https://apps.apple.com/app/id{$storeId}",
            default => '',
        };
    }
}
