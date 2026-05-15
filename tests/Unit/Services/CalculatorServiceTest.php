<?php

use App\Models\MetalPrice;
use App\Services\Mobile\CalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('calculator service computes total from ppm and market prices', function () {
    $metalPrice = MetalPrice::factory()->create([
        'pt_usd_per_oz' => 1000,
        'pd_usd_per_oz' => 1000,
        'rh_usd_per_oz' => 1000,
        'fetched_at' => now(),
    ]);

    $service = app(CalculatorService::class);
    $result = $service->estimate(
        weight: 2000,
        ptPpm: 1000,
        pdPpm: 1000,
        rhPpm: 1000,
        metalPrice: $metalPrice,
        ptRate: 1.0,
        pdRate: 1.0,
        rhRate: 1.0,
        currency: 'USD',
    );

    $gramsPerMetal = 2.0; // 2kg => 2000g, 1000ppm => 2g
    $expectedPerMetal = $gramsPerMetal * ($metalPrice->pt_usd_per_oz / 31.1043);
    $expectedTotal = round($expectedPerMetal * 3, 2);

    expect($result['totalUsd'])->toBe($expectedTotal);
});

test('calculator service uses page fields in pricing formula', function () {
    $metalPrice = MetalPrice::factory()->create([
        'pt_usd_per_oz' => 1000,
        'pd_usd_per_oz' => 1000,
        'rh_usd_per_oz' => 1000,
        'fetched_at' => now(),
    ]);

    $service = app(CalculatorService::class);
    $result = $service->estimate(
        weight: 1.5,
        weightUnit: 'kg',
        ptPpm: 1000,
        pdPpm: 500,
        rhPpm: 250,
        metalPrice: $metalPrice,
        ptUsdPerGram: 10,
        pdUsdPerGram: 20,
        rhUsdPerGram: 30,
        ptRate: 1.0,
        pdRate: 0.5,
        rhRate: 0.25,
        humidityRate: 0.1,
        currency: 'USD',
    );

    // weight=1.5kg => 1500g, then ppm to grams (1.5, 0.75, 0.375), and dry factor=0.9
    $expectedTotal = round((1.5 * 10 * 1.0 * 0.9) + (0.75 * 20 * 0.5 * 0.9) + (0.375 * 30 * 0.25 * 0.9), 2);

    expect($result['totalUsd'])->toBe($expectedTotal);
    expect($result['inputs']['weightUnit'])->toBe('kg');
    expect($result['inputs']['humidityRate'])->toBe(0.1);
});

test('calculator service accepts percent-style rates and humidity', function () {
    $metalPrice = MetalPrice::factory()->create([
        'pt_usd_per_oz' => 2113.00,
        'pd_usd_per_oz' => 1460.00,
        'rh_usd_per_oz' => 9500.00,
        'fetched_at' => now(),
    ]);

    $service = app(CalculatorService::class);
    $result = $service->estimate(
        weight: 100,
        weightUnit: 'kg',
        ptPpm: 100,
        pdPpm: 100,
        rhPpm: 100,
        metalPrice: $metalPrice,
        ptUsdPerGram: 67.93,
        pdUsdPerGram: 46.94,
        rhUsdPerGram: 305.43,
        ptRate: 98,
        pdRate: 98,
        rhRate: 90,
        humidityRate: 50,
        currency: 'USD',
    );

    expect($result['inputs']['ptRate'])->toBe(0.98);
    expect($result['inputs']['pdRate'])->toBe(0.98);
    expect($result['inputs']['rhRate'])->toBe(0.9);
    expect($result['inputs']['humidityRate'])->toBe(0.5);
    expect($result['totalUsd'])->toBe(1937.3);
});

test('calculator service accepts rates encoded as percent fractions', function () {
    $metalPrice = MetalPrice::factory()->create([
        'pt_usd_per_oz' => 1996.00,
        'pd_usd_per_oz' => 1409.00,
        'rh_usd_per_oz' => 9450.00,
        'fetched_at' => now(),
    ]);

    $service = app(CalculatorService::class);
    $result = $service->estimate(
        weight: 182,
        weightUnit: 'g',
        ptPpm: 120,
        pdPpm: 350,
        rhPpm: 12,
        metalPrice: $metalPrice,
        ptUsdPerGram: 64.17,
        pdUsdPerGram: 45.30,
        rhUsdPerGram: 303.82,
        ptRate: 0.0098,
        pdRate: 0.0098,
        rhRate: 0.009,
        humidityRate: 5,
        currency: 'USD',
    );

    expect($result['inputs']['ptRate'])->toBe(0.98);
    expect($result['inputs']['pdRate'])->toBe(0.98);
    expect($result['inputs']['rhRate'])->toBe(0.9);
    expect($result['totalUsd'])->toBe(4.56);
});
