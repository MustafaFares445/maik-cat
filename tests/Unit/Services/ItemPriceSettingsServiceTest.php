<?php

use App\Models\Item;
use App\Models\Setting;
use App\Services\Mobile\ItemPriceService;
use App\Services\Mobile\ItemPriceSettingsService;
use App\Services\Mobile\MetalsSpotService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('item price service applies the configured rate percentage', function (): void {
    $metals = Mockery::mock(MetalsSpotService::class);
    $metals->shouldReceive('all')
        ->with('USD')
        ->once()
        ->andReturn([
            'data' => [
                [
                    'key' => 'platinum',
                    'price_oz' => 311.043,
                    'price_gram' => 10.0,
                ],
            ],
        ]);

    $settings = Mockery::mock(ItemPriceSettingsService::class);
    $settings->shouldReceive('ratePercent')
        ->once()
        ->andReturn(50.0);

    $service = new ItemPriceService($metals, $settings);
    $item = new Item([
        'weight_kg' => 1.0,
        'pt_ppm' => 1000,
        'pd_ppm' => 0,
        'rh_ppm' => 0,
    ]);

    expect($service->priceFor($item))->toBe(5.0)
        ->and($service->priceForRate($item, 75.0))->toBe(7.5);
});

test('item price settings persist the dashboard rate', function (): void {
    $service = app(ItemPriceSettingsService::class);

    expect($service->ratePercent())->toBe(80.0);

    $updatedRate = $service->updateRatePercent(72.5);

    expect($updatedRate)->toBe(72.5)
        ->and($service->ratePercent())->toBe(72.5)
        ->and(Setting::query()->whereKey(ItemPriceSettingsService::RATE_PERCENT_KEY)->value('value'))->toBe('72.5');
});
