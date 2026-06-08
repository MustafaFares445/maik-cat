<?php

use App\Models\Item;
use App\Services\Mobile\ItemPriceService;
use App\Services\Mobile\MetalsSpotService;

test('item price service uses the live metals snapshot once per currency', function (): void {
    $spotPayload = [
        'source' => 'metal-sentinel',
        'cached' => false,
        'currency' => 'USD',
        'fx_rate' => 1.0,
        'updated_at' => now()->toIso8601String(),
        'data' => [
            [
                'key' => 'platinum',
                'price_gram' => 10.0,
                'price_oz' => 311.04,
            ],
            [
                'key' => 'palladium',
                'price_gram' => 20.0,
                'price_oz' => 622.07,
            ],
            [
                'key' => 'rhodium',
                'price_gram' => 30.0,
                'price_oz' => 933.11,
            ],
        ],
    ];

    $mock = \Mockery::mock(MetalsSpotService::class);
    $mock->shouldReceive('all')
        ->with('USD')
        ->once()
        ->andReturn($spotPayload);
    app()->instance(MetalsSpotService::class, $mock);

    $service = app(ItemPriceService::class);

    $firstItem = new Item([
        'weight_kg' => 1.0,
        'pt_ppm' => 1000,
        'pd_ppm' => 500,
        'rh_ppm' => 250,
    ]);

    $secondItem = new Item([
        'weight_kg' => 2.0,
        'pt_ppm' => 1000,
        'pd_ppm' => 500,
        'rh_ppm' => 250,
    ]);

    expect($service->priceFor($firstItem))->toBe(27.5)
        ->and($service->priceFor($secondItem))->toBe(55.0);
});
