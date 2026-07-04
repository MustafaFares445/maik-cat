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

    $mock = Mockery::mock(MetalsSpotService::class);
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

    expect($service->priceFor($firstItem))->toBe(17.88)
        ->and($service->priceFor($secondItem))->toBe(35.75);
});

test('item price service matches Ecotrade catalogue pricing for legacy oversized Ecotrade weights', function (): void {
    $spotPayload = [
        'source' => 'metal-sentinel',
        'cached' => false,
        'currency' => 'EUR',
        'fx_rate' => 1.0,
        'updated_at' => now()->toIso8601String(),
        'data' => [
            [
                'key' => 'platinum',
                'price_gram' => 46.06,
                'price_oz' => 1432.51,
            ],
            [
                'key' => 'palladium',
                'price_gram' => 35.17,
                'price_oz' => 1094.06,
            ],
            [
                'key' => 'rhodium',
                'price_gram' => 216.5,
                'price_oz' => 6734.04,
            ],
        ],
    ];

    $mock = Mockery::mock(MetalsSpotService::class);
    $mock->shouldReceive('all')
        ->with('EUR')
        ->once()
        ->andReturn($spotPayload);
    app()->instance(MetalsSpotService::class, $mock);

    $item = new Item([
        'weight_kg' => 6649,
        'pt_ppm' => 2750,
        'pd_ppm' => 0,
        'rh_ppm' => 0,
        'source' => 'ecotrade',
        'source_url' => 'https://www.ecotradegroup.com/en/product/volvo/8670409',
    ]);

    expect(app(ItemPriceService::class)->priceFor($item, 'EUR'))->toBe(132.88);
});
