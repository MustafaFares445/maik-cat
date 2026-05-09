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
        'cache_ttl' => (int) env('METALS_CACHE_TTL_SECONDS', 120),
        'timeout' => (int) env('METALS_HTTP_TIMEOUT_SECONDS', 8),
        'base_url' => env('METAL_SENTINEL_BASE_URL', 'https://metal-sentinel.p.rapidapi.com'),
        'rapidapi_key' => env('METAL_SENTINEL_RAPIDAPI_KEY'),
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
