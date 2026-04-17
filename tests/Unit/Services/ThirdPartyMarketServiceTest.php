<?php

use App\Models\MetalPrice;
use App\Services\Mobile\ThirdPartyMarketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('third party market service returns normalized last 14 changes', function () {
    config()->set('services.market_feed.changes_url', 'https://feed.test/changes');

    $changes = collect(range(1, 18))->map(function (int $i): array {
        return [
            'date' => now()->subDays(18 - $i)->toDateString(),
            'pt_price' => 1000 + $i,
            'pd_price' => 900 + $i,
            'rh_price' => 3000 + $i,
        ];
    })->all();

    Http::fake([
        'feed.test/*' => Http::response(['changes' => $changes], 200),
    ]);

    $service = app(ThirdPartyMarketService::class);
    $result = $service->changes(14);

    expect($result)->toHaveCount(14)
        ->and($result[0])->toHaveKeys([
            'date',
            'pt_usd_per_oz',
            'pd_usd_per_oz',
            'rh_usd_per_oz',
            'pt_change_percent',
            'pd_change_percent',
            'rh_change_percent',
        ]);
});

test('third party market service falls back to local daily history', function () {
    config()->set('services.market_feed.changes_url', 'https://feed.test/changes');

    Http::fake([
        'feed.test/*' => Http::response([], 500),
    ]);

    foreach (range(0, 16) as $index) {
        MetalPrice::factory()->create([
            'pt_usd_per_oz' => 1000 + $index,
            'pd_usd_per_oz' => 900 + $index,
            'rh_usd_per_oz' => 3000 + $index,
            'fetched_at' => now()->subDays(16 - $index),
        ]);
    }

    $service = app(ThirdPartyMarketService::class);
    $result = $service->changes(14);

    expect($result)->toHaveCount(14);
});
