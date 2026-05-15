<?php

use App\Enums\NotificationType;
use App\Models\CarGroup;
use App\Models\ExtraCode;
use App\Models\Item;
use App\Models\MetalPrice;
use App\Services\Mobile\MetalsSpotService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

test('search endpoint accepts text and categoryId', function () {
    $toyota = CarGroup::factory()->create(['name' => 'TOYOTA']);
    $lexus = CarGroup::factory()->create(['name' => 'LEXUS']);

    $matching = Item::factory()->create([
        'car_group_id' => $toyota->id,
        'serial_code' => '42004-9842',
        'model' => 'PRIUS HYBRID',
    ]);

    ExtraCode::factory()->create([
        'item_id' => $matching->id,
        'code' => 'PR-ALT-01',
    ]);

    Item::factory()->create([
        'car_group_id' => $lexus->id,
        'serial_code' => '83910-1210',
        'model' => 'COROLLA SENSOR',
    ]);

    $response = getJson("/api/items?text=PR-ALT-01&categoryId={$toyota->id}");

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $matching->id);
});

test('item search endpoint accepts text and carGroup', function () {
    $opel = CarGroup::factory()->create(['name' => 'OPEL', 'excel_sheet_name' => 'OPEL']);
    $japan = CarGroup::factory()->create(['name' => 'JAPAN', 'excel_sheet_name' => 'JAPAN']);

    $matching = Item::factory()->create([
        'car_group_id' => $opel->id,
        'serial_code' => 'GM10',
        'model' => 'VAUXHAL',
    ]);

    Item::factory()->create([
        'car_group_id' => $japan->id,
        'serial_code' => 'GM10',
        'model' => 'TOYOTA PRIUS',
    ]);

    $response = getJson('/api/items?text=GM10&carGroup=OPEL');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $matching->id);
});

test('home endpoint returns last 14 changes from metal sentinel spot data', function () {
    $payloadUsd = [
        'source' => 'metal-sentinel',
        'cached' => false,
        'currency' => 'USD',
        'fx_rate' => 1.0,
        'updated_at' => now()->toIso8601String(),
        'data' => [
            ['key' => 'platinum', 'price_oz' => 1520.0, 'change_pct' => 0.1],
            ['key' => 'palladium', 'price_oz' => 1020.0, 'change_pct' => -0.2],
            ['key' => 'rhodium', 'price_oz' => 4020.0, 'change_pct' => 0.3],
        ],
    ];

    $mock = \Mockery::mock(MetalsSpotService::class);
    $mock->shouldReceive('all')->with('USD')->andReturn($payloadUsd);
    app()->instance(MetalsSpotService::class, $mock);

    $response = getJson('/api/home/stats');

    $response->assertOk();
    $response->assertJsonPath('stats.source', 'metal-sentinel');
    $response->assertJsonCount(14, 'stats.changes');
});

test('charts endpoint returns last 14 daily points from metal sentinel spot data', function () {
    $payloadUsd = [
        'source' => 'metal-sentinel',
        'cached' => false,
        'currency' => 'USD',
        'fx_rate' => 1.0,
        'updated_at' => now()->toIso8601String(),
        'data' => [
            ['key' => 'platinum', 'price_oz' => 1555.0, 'change_pct' => 0.5],
            ['key' => 'palladium', 'price_oz' => 933.0, 'change_pct' => -0.5],
            ['key' => 'rhodium', 'price_oz' => 4300.0, 'change_pct' => 0.25],
        ],
    ];

    $mock = \Mockery::mock(MetalsSpotService::class);
    $mock->shouldReceive('all')->with('USD')->andReturn($payloadUsd);
    app()->instance(MetalsSpotService::class, $mock);

    $response = getJson('/api/charts/metals');

    $response->assertOk();
    $response->assertJsonPath('period', '14_days');
    $response->assertJsonCount(14, 'points');
    $response->assertJsonStructure([
        'points' => [
            ['ptUsdPerOz', 'pdUsdPerOz', 'rhUsdPerOz'],
        ],
    ]);
});

test('calculator endpoint returns estimate breakdown and total', function () {
    $price = MetalPrice::factory()->create([
        'pt_usd_per_oz' => 1555.2150,
        'pd_usd_per_oz' => 933.1290,
        'rh_usd_per_oz' => 4300.0000,
        'fetched_at' => now(),
    ]);

    $expected = round((1 * ($price->pt_usd_per_oz / 31.1043)) + (0.5 * ($price->pd_usd_per_oz / 31.1043)), 2);

    $response = postJson('/api/calculator/estimate', [
        'weight' => 1000,
        'ptPpm' => 1000,
        'pdPpm' => 500,
        'rhPpm' => 0,
        'ptRate' => 1,
        'pdRate' => 1,
        'rhRate' => 1,
        'currency' => 'USD',
    ]);

    $response->assertOk();
    $response->assertJsonPath('estimate.currency', 'USD');
    expect((float) $response->json('estimate.totalUsd'))->toBe($expected);
});

test('calculator endpoint accepts full calculator page fields', function () {
    MetalPrice::factory()->create([
        'pt_usd_per_oz' => 1500,
        'pd_usd_per_oz' => 1500,
        'rh_usd_per_oz' => 1500,
        'fetched_at' => now(),
    ]);

    $response = postJson('/api/calculator/estimate', [
        'weight' => 2,
        'weightUnit' => 'kg',
        'ptPpm' => 1000,
        'pdPpm' => 500,
        'rhPpm' => 250,
        'ptUsdPerGram' => 66.13,
        'pdUsdPerGram' => 46.30,
        'rhUsdPerGram' => 280.28,
        'ptRate' => 0.98,
        'pdRate' => 0.98,
        'rhRate' => 0.90,
        'humidityRate' => 0.1,
        'currency' => 'USD',
    ]);

    $response->assertOk();
    $response->assertJsonPath('estimate.inputs.weightUnit', 'kg');
    $response->assertJsonPath('estimate.inputs.ptRate', 0.98);
    $response->assertJsonPath('estimate.inputs.humidityRate', 0.1);
    expect((float) $response->json('estimate.totalUsd'))->toBeGreaterThan(0);
});

test('calculator endpoint accepts whole-number percent inputs', function () {
    MetalPrice::factory()->create([
        'pt_usd_per_oz' => 2113.00,
        'pd_usd_per_oz' => 1460.00,
        'rh_usd_per_oz' => 9500.00,
        'fetched_at' => now(),
    ]);

    $response = postJson('/api/calculator/estimate', [
        'weight' => 100,
        'weightUnit' => 'kg',
        'ptPpm' => 100,
        'pdPpm' => 100,
        'rhPpm' => 100,
        'ptUsdPerGram' => 67.93,
        'pdUsdPerGram' => 46.94,
        'rhUsdPerGram' => 305.43,
        'ptRate' => 98,
        'pdRate' => 98,
        'rhRate' => 90,
        'humidityRate' => 50,
        'currency' => 'USD',
    ]);

    $response->assertOk();
    $response->assertJsonPath('estimate.inputs.ptRate', 0.98);
    $response->assertJsonPath('estimate.inputs.humidityRate', 0.5);
    $response->assertJsonPath('estimate.totalUsd', 1937.3);
});

test('details endpoint returns converter and related entries', function () {
    $group = CarGroup::factory()->create();

    $target = Item::factory()->create(['car_group_id' => $group->id]);
    $related = Item::factory()->create(['car_group_id' => $group->id]);

    $response = getJson("/api/items/{$target->id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $target->id);
    $response->assertJsonPath('data.carGroup.id', $group->id);
    $response->assertJsonPath('related.0.id', $related->id);
});

test('item details endpoint returns camelCase converter payload', function () {
    $converter = Item::factory()->create();

    $response = getJson("/api/items/{$converter->id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $converter->id);
    $response->assertJsonPath('data.serialCode', $converter->serial_code);
});

test('similar items endpoint returns same car group items', function () {
    $group = CarGroup::factory()->create();

    $target = Item::factory()->create(['car_group_id' => $group->id]);
    $similar = Item::factory()->count(3)->create(['car_group_id' => $group->id]);
    Item::factory()->create();

    $response = getJson("/api/items/{$target->id}/similar");

    $response->assertOk();
    $response->assertJsonCount(3, 'data');
    $response->assertJsonPath('data.0.carGroup.id', $group->id);
    $response->assertJsonMissing(['id' => $target->id]);
    $response->assertJsonFragment(['id' => $similar[0]->id]);
    $response->assertJsonFragment(['id' => $similar[1]->id]);
    $response->assertJsonFragment(['id' => $similar[2]->id]);
});

test('notifications endpoint returns last 14 change notifications', function () {
    $payloadUsd = [
        'source' => 'metal-sentinel',
        'cached' => false,
        'currency' => 'USD',
        'fx_rate' => 1.0,
        'updated_at' => now()->toIso8601String(),
        'data' => [
            ['key' => 'platinum', 'price_oz' => 1210.0, 'change_pct' => 0.1],
            ['key' => 'palladium', 'price_oz' => 810.0, 'change_pct' => 0.2],
            ['key' => 'rhodium', 'price_oz' => 3210.0, 'change_pct' => 0.3],
        ],
    ];

    $mock = \Mockery::mock(MetalsSpotService::class);
    $mock->shouldReceive('all')->with('USD')->andReturn($payloadUsd);
    app()->instance(MetalsSpotService::class, $mock);

    $response = getJson('/api/notifications/changes');

    $response->assertOk();
    $response->assertJsonCount(14, 'data');
    $response->assertJsonPath('data.0.type', NotificationType::CHANGE_MARKET_PRICE);
    $response->assertJsonPath('data.0.iconUrl', NotificationType::iconUrl(NotificationType::CHANGE_MARKET_PRICE));
    $response->assertJsonPath('data.0.imageUrl', NotificationType::iconUrl(NotificationType::CHANGE_MARKET_PRICE));
    $response->assertJsonStructure([
        'data' => [
            ['iconUrl', 'imageUrl', 'meta' => ['ptUsdPerOz', 'pdUsdPerOz', 'rhUsdPerOz']],
        ],
    ]);
});
