<?php

use App\Services\Mobile\MetalsSpotService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

test('metals spot endpoint returns normalized payload', function () {
    $mock = \Mockery::mock(MetalsSpotService::class);
    app()->instance(MetalsSpotService::class, $mock);
    $mock->shouldReceive('all')->once()->andReturn([
        'source' => 'kitco.com',
        'cached' => true,
        'updated_at' => now()->toIso8601String(),
        'data' => [
            [
                'key' => 'platinum',
                'name_en' => 'Platinum',
                'name_ar' => 'بلاتين',
                'symbol' => 'Pt',
                'price_oz' => 982.40,
                'price_gram' => 31.58,
                'change_oz' => 11.79,
                'change_pct' => 1.20,
                'direction' => 'up',
            ],
        ],
    ]);

    $response = getJson('/api/v1/metals/spot');

    $response->assertOk();
    $response->assertJsonPath('success', true);
    $response->assertJsonPath('data.0.key', 'platinum');
    $response->assertJsonPath('data.0.direction', 'up');
    $response->assertJsonPath('data.0.priceOz', 982.4);
    $response->assertJsonPath('data.0.priceGram', 31.58);
    $response->assertJsonPath('data.0.changePct', 1.2);
});

test('metals spot endpoint filters by metals list', function () {
    $mock = \Mockery::mock(MetalsSpotService::class);
    app()->instance(MetalsSpotService::class, $mock);
    $mock->shouldReceive('all')->once()->andReturn([
        'source' => 'api.metals.live',
        'cached' => false,
        'updated_at' => now()->toIso8601String(),
        'data' => [
            ['key' => 'gold', 'price_oz' => 1, 'price_gram' => 2, 'change_oz' => 0, 'change_pct' => 0, 'direction' => 'flat'],
            ['key' => 'silver', 'price_oz' => 1, 'price_gram' => 2, 'change_oz' => 0, 'change_pct' => 0, 'direction' => 'flat'],
        ],
    ]);

    $response = getJson('/api/v1/metals/spot?metals=gold');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.key', 'gold');
    $response->assertJsonPath('data.0.priceOz', 1);
});

test('single metal endpoint returns not found for unknown metal', function () {
    $mock = \Mockery::mock(MetalsSpotService::class);
    app()->instance(MetalsSpotService::class, $mock);
    $mock->shouldReceive('find')->with('unobtainium', 'USD')->once()->andReturn(null);

    $response = getJson('/api/v1/metals/spot/unobtainium');

    $response->assertNotFound();
    $response->assertJsonPath('error', 'not_found');
});

test('metals spot endpoint returns 503 when source is unavailable', function () {
    $mock = \Mockery::mock(MetalsSpotService::class);
    app()->instance(MetalsSpotService::class, $mock);
    $mock->shouldReceive('all')->once()->andThrow(new RuntimeException('All price sources are currently unreachable.'));

    $response = getJson('/api/v1/metals/spot');

    $response->assertStatus(503);
    $response->assertJsonPath('error', 'upstream_unavailable');
});
