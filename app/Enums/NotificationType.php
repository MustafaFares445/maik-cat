<?php

namespace App\Enums;

final class NotificationType
{
    public const AUTH_LOGIN_NEW_DEVICE = 'auth_login_new_device';

    public const ADD_NEW_ITEM = 'add_new_item';

    public const CHANGE_MARKET_PRICE = 'change_market_price';

    public const GENERALE_NOTIFICATION = 'generale_notification';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [
            self::AUTH_LOGIN_NEW_DEVICE,
            self::ADD_NEW_ITEM,
            self::CHANGE_MARKET_PRICE,
            self::GENERALE_NOTIFICATION,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::AUTH_LOGIN_NEW_DEVICE => 'Auth Login New Device',
            self::ADD_NEW_ITEM => 'Add New Item',
            self::CHANGE_MARKET_PRICE => 'Change Market Price',
            self::GENERALE_NOTIFICATION => 'Generale Notification',
        ];
    }

    public static function normalize(string $type): string
    {
        $normalized = str($type)
            ->trim()
            ->lower()
            ->replace(['-', ' '], '_')
            ->replaceMatches('/_+/', '_')
            ->toString();

        if ($normalized === '') {
            return self::GENERALE_NOTIFICATION;
        }

        return in_array($normalized, self::values(), true)
            ? $normalized
            : self::GENERALE_NOTIFICATION;
    }

    public static function iconPath(string $type): string
    {
        $type = self::normalize($type);

        return "images/notifications/{$type}.svg";
    }

    public static function imageUrl(string $type): string
    {
        return self::iconUrl($type);
    }

    public static function iconUrl(string $type): string
    {
        return asset(self::iconPath($type));
    }
}
