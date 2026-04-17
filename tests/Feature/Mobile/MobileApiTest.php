<?php

use App\Models\CarGroup;
use App\Models\Item;
use App\Models\ExtraCode;
use App\Models\MetalPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

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

test('home endpoint returns last 14 changes from third party feed', function () {
    config()->set('services.market_feed.changes_url', 'https://feed.test/changes');

    $changes = collect(range(1, 20))->map(function (int $i): array {
        return [
            'date' => now()->subDays(20 - $i)->toDateString(),
            'pt_usd_per_oz' => 1500 + $i,
            'pd_usd_per_oz' => 1000 + $i,
            'rh_usd_per_oz' => 4000 + $i,
            'pt_change_percent' => 0.1,
            'pd_change_percent' => -0.2,
            'rh_change_percent' => 0.3,
        ];
    })->all();

    Http::fake([
        'feed.test/*' => Http::response(['changes' => $changes], 200),
    ]);

    $response = getJson('/api/home/stats');

    $response->assertOk();
    $response->assertJsonPath('stats.source', 'third_party');
    $response->assertJsonCount(14, 'stats.changes');
});

test('charts endpoint returns last 14 daily points from fallback source', function () {
    config()->set('services.market_feed.changes_url', '');

    foreach (range(0, 15) as $dayOffset) {
        MetalPrice::factory()->create([
            'pt_usd_per_oz' => 1500 + $dayOffset,
            'pd_usd_per_oz' => 1000 + $dayOffset,
            'rh_usd_per_oz' => 4000 + $dayOffset,
            'fetched_at' => now()->subDays(15 - $dayOffset),
        ]);
    }

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
    $converter = Item::factory()->create([
        'weight_kg' => 1.000,
        'pt_ppm' => 1000,
        'pd_ppm' => 500,
        'rh_ppm' => 0,
    ]);

    $price = MetalPrice::factory()->create([
        'pt_usd_per_oz' => 1555.2150,
        'pd_usd_per_oz' => 933.1290,
        'rh_usd_per_oz' => 4300.0000,
        'fetched_at' => now(),
    ]);

    $expected = round((1 * ($price->pt_usd_per_oz / 31.1043)) + (0.5 * ($price->pd_usd_per_oz / 31.1043)), 2);

    $response = postJson('/api/calculator/estimate', [
        'itemId' => $converter->id,
        'recoveryRate' => 1,
    ]);

    $response->assertOk();
    $response->assertJsonPath('item.id', $converter->id);
    $response->assertJsonPath('item.serialCode', $converter->serial_code);
    expect((float) $response->json('estimate.totalUsd'))->toBe($expected);
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
    config()->set('services.market_feed.changes_url', 'https://feed.test/changes');

    $changes = collect(range(1, 16))->map(function (int $i): array {
        return [
            'date' => now()->subDays(16 - $i)->toDateString(),
            'pt_usd_per_oz' => 1200 + $i,
            'pd_usd_per_oz' => 800 + $i,
            'rh_usd_per_oz' => 3200 + $i,
            'pt_change_percent' => 0.1,
            'pd_change_percent' => 0.2,
            'rh_change_percent' => 0.3,
        ];
    })->all();

    Http::fake([
        'feed.test/*' => Http::response(['changes' => $changes], 200),
    ]);

    $response = getJson('/api/notifications/changes');

    $response->assertOk();
    $response->assertJsonCount(14, 'data');
    $response->assertJsonStructure([
        'data' => [
            ['meta' => ['ptUsdPerOz', 'pdUsdPerOz', 'rhUsdPerOz']],
        ],
    ]);
});
