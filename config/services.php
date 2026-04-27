<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'market_feed' => [
        'home_url' => env('MARKET_FEED_HOME_URL'),
        'changes_url' => env('MARKET_FEED_CHANGES_URL'),
        'token' => env('MARKET_FEED_TOKEN'),
        'timeout' => (int) env('MARKET_FEED_TIMEOUT', 10),
    ],

    'metals' => [
        'cache_ttl' => (int) env('METALS_CACHE_TTL_SECONDS', 21600),
        'timeout' => (int) env('METALS_HTTP_TIMEOUT_SECONDS', 8),
        'fallback' => (bool) env('METALS_FALLBACK_ENABLED', true),
        'hard_fallback_enabled' => (bool) env('METALS_HARD_FALLBACK_ENABLED', true),
        'hard_fallback_prices' => [
            'gold' => (float) env('METALS_HARD_FALLBACK_GOLD_OZ', 2350.00),
            'silver' => (float) env('METALS_HARD_FALLBACK_SILVER_OZ', 28.00),
            'platinum' => (float) env('METALS_HARD_FALLBACK_PLATINUM_OZ', 1864.94),
            'palladium' => (float) env('METALS_HARD_FALLBACK_PALLADIUM_OZ', 1377.89),
            'rhodium' => (float) env('METALS_HARD_FALLBACK_RHODIUM_OZ', 9270.06),
        ],
        'live_url' => env('METALS_LIVE_URL', 'https://api.metals.live/v1/spot'),
        'kitco_url' => env('METALS_KITCO_URL', 'https://www.kitco.com/price/precious-metals/text-quotes'),
    ],

    'currency' => [
        'public_url' => env('CURRENCY_PUBLIC_API_URL', 'https://api.frankfurter.app/latest'),
        'timeout' => (int) env('CURRENCY_HTTP_TIMEOUT_SECONDS', 6),
        'cache_ttl' => (int) env('CURRENCY_CACHE_TTL_SECONDS', 1800),
    ],

    'firebase' => [
        'credentials' => storage_path(env('FIREBASE_CREDENTIALS', 'firebase.json')),
        'project_id' => env('FIREBASE_PROJECT_ID'),
    ],

];
