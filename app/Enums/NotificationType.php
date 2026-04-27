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

    public static function iconPath(string $type): string
    {
        if (! in_array($type, self::values(), true)) {
            $type = self::GENERALE_NOTIFICATION;
        }

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
