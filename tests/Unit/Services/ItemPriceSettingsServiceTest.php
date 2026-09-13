<?php

use App\Models\Item;
use App\Models\Setting;
use App\Services\Mobile\ItemPriceService;
use App\Services\Mobile\ItemPriceSettingsService;
use App\Services\Mobile\MetalsSpotService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('item price service applies metal deductions before the configured rate percentage', function (): void {
    $metals = Mockery::mock(MetalsSpotService::class);
    $metals->shouldReceive('all')
        ->with('USD')
        ->once()
        ->andReturn([
            'data' => [
                ['key' => 'platinum', 'price_oz' => 311.043, 'price_gram' => 10.0],
                ['key' => 'palladium', 'price_oz' => 622.086, 'price_gram' => 20.0],
                ['key' => 'rhodium', 'price_oz' => 933.129, 'price_gram' => 30.0],
            ],
        ]);

    $settings = Mockery::mock(ItemPriceSettingsService::class);
    $settings->shouldReceive('pricingConfiguration')
        ->twice()
        ->andReturn([
            'rate_percent' => 50.0,
            'platinum_deduction_percent' => 2.0,
            'palladium_deduction_percent' => 2.0,
            'rhodium_deduction_percent' => 10.0,
        ]);

    $service = new ItemPriceService($metals, $settings);
    $item = new Item([
        'weight_kg' => 1.0,
        'pt_ppm' => 1000,
        'pd_ppm' => 1000,
        'rh_ppm' => 1000,
    ]);

    expect($service->priceFor($item))->toBe(28.2)
        ->and($service->priceForRate($item, 75.0))->toBe(42.3)
        ->and($service->priceForConfiguration($item, 100.0, 0.0, 0.0, 0.0))->toBe(60.0);
});

test('item price settings expose defaults and persist dashboard configuration', function (): void {
    $service = app(ItemPriceSettingsService::class);

    expect($service->pricingConfiguration())->toBe([
        'rate_percent' => 80.0,
        'platinum_deduction_percent' => 2.0,
        'palladium_deduction_percent' => 2.0,
        'rhodium_deduction_percent' => 10.0,
    ]);

    $updated = $service->updatePricingConfiguration(72.5, 1.5, 3.0, 12.0);

    expect($updated)->toBe([
        'rate_percent' => 72.5,
        'platinum_deduction_percent' => 1.5,
        'palladium_deduction_percent' => 3.0,
        'rhodium_deduction_percent' => 12.0,
    ])->and(Setting::query()->whereKey(ItemPriceSettingsService::RATE_PERCENT_KEY)->value('value'))->toBe('72.5')
        ->and(Setting::query()->whereKey(ItemPriceSettingsService::PLATINUM_DEDUCTION_PERCENT_KEY)->value('value'))->toBe('1.5')
        ->and(Setting::query()->whereKey(ItemPriceSettingsService::PALLADIUM_DEDUCTION_PERCENT_KEY)->value('value'))->toBe('3')
        ->and(Setting::query()->whereKey(ItemPriceSettingsService::RHODIUM_DEDUCTION_PERCENT_KEY)->value('value'))->toBe('12');
});
