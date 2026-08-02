<?php

return [
    'brand_name' => env('LEGAL_BRAND_NAME', 'Maik Cat'),
    'operator_name' => env('LEGAL_OPERATOR_NAME', 'Maik Cat'),
    'support_email' => env('LEGAL_SUPPORT_EMAIL'),
    'support_phone' => env('LEGAL_SUPPORT_PHONE'),
    'website_url' => env('LEGAL_WEBSITE_URL', env('APP_URL')),
    'effective_date' => env('LEGAL_EFFECTIVE_DATE', '2026-08-02'),
    'jurisdiction' => env('LEGAL_JURISDICTION'),
];
