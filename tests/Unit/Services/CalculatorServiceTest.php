<?php

use App\Models\Item;
use App\Models\MetalPrice;
use App\Services\Mobile\CalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('calculator service computes total from ppm and market prices', function () {
    $converter = Item::factory()->create([
        'weight_kg' => 2.000,
        'pt_ppm' => 1000,
        'pd_ppm' => 1000,
        'rh_ppm' => 1000,
    ]);

    $metalPrice = MetalPrice::factory()->create([
        'pt_usd_per_oz' => 1000,
        'pd_usd_per_oz' => 1000,
        'rh_usd_per_oz' => 1000,
        'fetched_at' => now(),
    ]);

    $service = app(CalculatorService::class);
    $result = $service->estimate($converter, $metalPrice, 1.0);

    $gramsPerMetal = 2.0; // 2kg => 2000g, 1000ppm => 2g
    $expectedPerMetal = $gramsPerMetal * ($metalPrice->pt_usd_per_oz / 31.1043);
    $expectedTotal = round($expectedPerMetal * 3, 2);

    expect($result['total_usd'])->toBe($expectedTotal);
});

