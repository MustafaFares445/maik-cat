<?php

use App\Services\Mobile\CurrencyConversionService;
use App\Services\Mobile\MetalsSpotService;
use App\Services\Mobile\ThirdPartyMarketService;

test('third party market service builds window from metals spot rows', function () {
    $spotUsd = [
        'source' => 'metal-sentinel',
        'data' => [
            ['key' => 'platinum', 'price_oz' => 1000.0, 'change_pct' => 0.5],
            ['key' => 'palladium', 'price_oz' => 900.0, 'change_pct' => -0.25],
            ['key' => 'rhodium', 'price_oz' => 3000.0, 'change_pct' => 0.1],
        ],
    ];

    $metals = \Mockery::mock(MetalsSpotService::class);
    $metals->shouldReceive('all')->with('USD')->once()->andReturn($spotUsd);

    $fx = \Mockery::mock(CurrencyConversionService::class);
    $fx->shouldReceive('rateOrOne')->never();

    $service = new ThirdPartyMarketService($metals, $fx);
    $result = $service->changes(14);

    expect($result)->toHaveCount(14)
        ->and($result[13])->toMatchArray([
            'pt_usd_per_oz' => 1000.0,
            'pd_usd_per_oz' => 900.0,
            'rh_usd_per_oz' => 3000.0,
            'pt_change_percent' => 0.5,
            'pd_change_percent' => -0.25,
            'rh_change_percent' => 0.1,
        ])
        ->and($result[0]['pt_change_percent'])->toBe(0.0);
});

test('third party market service maps EUR rows from sentinel EUR quotes', function () {
    $spotUsd = [
        'source' => 'metal-sentinel',
        'data' => [
            ['key' => 'platinum', 'price_oz' => 1100.0, 'change_pct' => 0.2],
            ['key' => 'palladium', 'price_oz' => 950.0, 'change_pct' => 0.3],
            ['key' => 'rhodium', 'price_oz' => 3100.0, 'change_pct' => 0.4],
        ],
    ];

    $spotEur = [
        'source' => 'metal-sentinel',
        'data' => [
            ['key' => 'platinum', 'price_oz' => 1000.0, 'change_pct' => 0.2],
            ['key' => 'palladium', 'price_oz' => 860.0, 'change_pct' => 0.3],
            ['key' => 'rhodium', 'price_oz' => 2800.0, 'change_pct' => 0.4],
        ],
    ];

    $metals = \Mockery::mock(MetalsSpotService::class);
    $metals->shouldReceive('all')->with('USD')->once()->andReturn($spotUsd);
    $metals->shouldReceive('all')->with('EUR')->once()->andReturn($spotEur);

    $fx = \Mockery::mock(CurrencyConversionService::class);
    $fx->shouldReceive('rateOrOne')->with('USD', 'EUR')->once()->andReturn(0.92);

    $service = new ThirdPartyMarketService($metals, $fx);
    $result = $service->changes(5, 'EUR');

    expect($result)->toHaveCount(5)
        ->and(collect($result)->last())->toMatchArray([
            'pt_eur_per_oz' => 1000.0,
            'currency' => 'EUR',
            'fx_rate' => 0.92,
        ]);
});
