<?php

use App\Models\Item;
use App\Models\Setting;
use App\Services\Mobile\ItemPriceService;
use App\Services\Mobile\ItemPriceSettingsService;
use App\Services\Mobile\MetalsSpotService;
use App\Services\Pricing\FilterPriceCorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pricingSettingsMetalService(): MetalsSpotService
{
    $metals = Mockery::mock(MetalsSpotService::class);
    $metals->shouldReceive('all')->with('USD')->andReturn([
        'data' => [
            ['key' => 'platinum', 'price_oz' => 311.043, 'price_gram' => 10.0],
            ['key' => 'palladium', 'price_oz' => 622.086, 'price_gram' => 20.0],
            ['key' => 'rhodium', 'price_oz' => 933.129, 'price_gram' => 30.0],
        ],
    ]);

    return $metals;
}

test('legacy pricing remains the default until metal deductions are enabled', function (): void {
    app()->instance(MetalsSpotService::class, pricingSettingsMetalService());

    $item = new Item([
        'weight_kg' => 1.0,
        'pt_ppm' => 1000,
        'pd_ppm' => 1000,
        'rh_ppm' => 1000,
    ]);

    expect(app(ItemPriceService::class)->priceFor($item))->toBe(48.0);
});

test('metal deductions apply before the configured rate when enabled', function (): void {
    app()->instance(MetalsSpotService::class, pricingSettingsMetalService());

    $item = new Item([
        'weight_kg' => 1.0,
        'pt_ppm' => 1000,
        'pd_ppm' => 1000,
        'rh_ppm' => 1000,
    ]);
    $service = app(ItemPriceService::class);

    expect($service->priceForConfiguration(
        $item,
        50.0,
        2.0,
        2.0,
        10.0,
        'USD',
        true,
        FilterPriceCorrectionService::MODE_DISABLED,
    ))->toBe(28.2);
});

test('pricing settings expose safe defaults and persist dashboard configuration', function (): void {
    $service = app(ItemPriceSettingsService::class);

    expect($service->pricingConfiguration())->toBe([
        'rate_percent' => 80.0,
        'metal_deductions_enabled' => false,
        'platinum_deduction_percent' => 2.0,
        'palladium_deduction_percent' => 2.0,
        'rhodium_deduction_percent' => 10.0,
        'filter_correction_mode' => FilterPriceCorrectionService::MODE_DISABLED,
        'filter_candidate_price_threshold' => 700.0,
        'filter_candidate_weight_threshold_kg' => 1.5,
    ]);

    $updated = $service->updatePricingConfiguration(
        72.5,
        true,
        1.5,
        3.0,
        12.0,
        FilterPriceCorrectionService::MODE_WEIGHT_ONLY,
        850.0,
        1.75,
    );

    expect($updated['metal_deductions_enabled'])->toBeTrue()
        ->and($updated['filter_correction_mode'])->toBe(FilterPriceCorrectionService::MODE_WEIGHT_ONLY)
        ->and($updated['filter_candidate_price_threshold'])->toBe(850.0)
        ->and($updated['filter_candidate_weight_threshold_kg'])->toBe(1.75)
        ->and(Setting::query()->whereKey(ItemPriceSettingsService::RATE_PERCENT_KEY)->value('value'))->toBe('72.5');
});

test('each enabled metal deduction affects only its own contribution', function (): void {
    app()->instance(MetalsSpotService::class, pricingSettingsMetalService());
    $service = app(ItemPriceService::class);
    $item = new Item([
        'weight_kg' => 1.0,
        'pt_ppm' => 1000,
        'pd_ppm' => 1000,
        'rh_ppm' => 1000,
    ]);

    expect($service->priceForConfiguration($item, 100.0, 0.0, 0.0, 0.0, 'USD', true))->toBe(60.0)
        ->and($service->priceForConfiguration($item, 100.0, 100.0, 0.0, 0.0, 'USD', true))->toBe(50.0)
        ->and($service->priceForConfiguration($item, 100.0, 0.0, 100.0, 0.0, 'USD', true))->toBe(40.0)
        ->and($service->priceForConfiguration($item, 100.0, 0.0, 0.0, 100.0, 'USD', true))->toBe(30.0);
});

test('pricing settings clamp percentages and thresholds', function (): void {
    $service = app(ItemPriceSettingsService::class);

    $updated = $service->updatePricingConfiguration(
        150.0,
        true,
        -5.0,
        55.0,
        110.0,
        'not-a-mode',
        -10.0,
        -2.0,
    );

    expect($updated['rate_percent'])->toBe(100.0)
        ->and($updated['platinum_deduction_percent'])->toBe(0.0)
        ->and($updated['palladium_deduction_percent'])->toBe(55.0)
        ->and($updated['rhodium_deduction_percent'])->toBe(100.0)
        ->and($updated['filter_correction_mode'])->toBe(FilterPriceCorrectionService::MODE_DISABLED)
        ->and($updated['filter_candidate_price_threshold'])->toBe(0.0)
        ->and($updated['filter_candidate_weight_threshold_kg'])->toBe(0.0);
});
