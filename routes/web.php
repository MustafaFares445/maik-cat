<?php

use App\Enums\AppPlatform;
use App\Models\AppVersion;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $defaultStoreIds = [
        AppPlatform::ANDROID => 'maik.cat.android',
        AppPlatform::IOS => '6800437659',
    ];
    $defaultStoreLinks = [
        AppPlatform::ANDROID => 'https://play.google.com/store/apps/details?id=maik.cat.android',
        AppPlatform::IOS => 'https://apps.apple.com/us/app/maik-cat/id6800437659',
    ];
    $placeholderStoreIds = ['com.example.app', '123456789'];
    $storeLinks = [];

    foreach (AppPlatform::values() as $platform) {
        try {
            $storeId = trim((string) AppVersion::query()
                ->where('platform', $platform)
                ->value('store_id'));
        } catch (\Throwable) {
            $storeId = '';
        }

        if ($storeId === '' || in_array($storeId, $placeholderStoreIds, true)) {
            $storeId = $defaultStoreIds[$platform] ?? '';
        }

        $storeLinks[$platform] = $storeId === ($defaultStoreIds[$platform] ?? null)
            ? ($defaultStoreLinks[$platform] ?? null)
            : ($storeId !== '' ? AppPlatform::storeUrl($platform, $storeId) : null);
    }

    return view('welcome', compact('storeLinks'));
});

Route::redirect('/privacy-policy', '/privacy-policy/en');
Route::view('/privacy-policy/en', 'legal.privacy-policy-en')->name('legal.privacy.en');
Route::view('/privacy-policy/ar', 'legal.privacy-policy-ar')->name('legal.privacy.ar');

Route::redirect('/terms-of-use', '/terms-of-use/en');
Route::view('/terms-of-use/en', 'legal.terms-of-use-en')->name('legal.terms.en');
Route::view('/terms-of-use/ar', 'legal.terms-of-use-ar')->name('legal.terms.ar');
