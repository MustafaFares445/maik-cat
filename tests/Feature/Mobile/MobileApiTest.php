<?php

use App\Enums\NotificationType;
use App\Models\CarGroup;
use App\Models\ExtraCode;
use App\Models\Item;
use App\Models\MetalPrice;
use App\Services\Mobile\MetalsSpotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

function mockItemMetalsSpotService(float $ptPricePerGram = 10.0, float $pdPricePerGram = 20.0, float $rhPricePerGram = 30.0): void
{
    $spot = [
        'source' => 'metal-sentinel',
        'cached' => false,
        'currency' => 'USD',
        'fx_rate' => 1.0,
        'updated_at' => now()->toIso8601String(),
        'data' => [
            [
                'key' => 'platinum',
                'name_en' => 'Platinum',
                'name_ar' => 'Ø¨Ù„Ø§ØªÙŠÙ†',
                'symbol' => 'Pt',
                'price_oz' => round($ptPricePerGram * 31.1035, 2),
                'price_gram' => $ptPricePerGram,
                'change_oz' => 0.0,
                'change_pct' => 0.0,
                'direction' => 'flat',
            ],
            [
                'key' => 'palladium',
                'name_en' => 'Palladium',
                'name_ar' => 'Ø¨Ù„Ø§Ø¯ÙŠÙˆÙ…',
                'symbol' => 'Pd',
                'price_oz' => round($pdPricePerGram * 31.1035, 2),
                'price_gram' => $pdPricePerGram,
                'change_oz' => 0.0,
                'change_pct' => 0.0,
                'direction' => 'flat',
            ],
            [
                'key' => 'rhodium',
                'name_en' => 'Rhodium',
                'name_ar' => 'Ø±ÙˆØ¯ÙŠÙˆÙ…',
                'symbol' => 'Rh',
                'price_oz' => round($rhPricePerGram * 31.1035, 2),
                'price_gram' => $rhPricePerGram,
                'change_oz' => 0.0,
                'change_pct' => 0.0,
                'direction' => 'flat',
            ],
        ],
    ];

    $mock = \Mockery::mock(MetalsSpotService::class);
    $mock->shouldReceive('all')->andReturn($spot);
    app()->instance(MetalsSpotService::class, $mock);
}

function mockItemMetalsSpotServiceAscii(float $ptPricePerGram = 10.0, float $pdPricePerGram = 20.0, float $rhPricePerGram = 30.0): void
{
    $spot = [
        'source' => 'metal-sentinel',
        'cached' => false,
        'currency' => 'USD',
        'fx_rate' => 1.0,
        'updated_at' => now()->toIso8601String(),
        'data' => [
            [
                'key' => 'platinum',
                'name_en' => 'Platinum',
                'symbol' => 'Pt',
                'price_oz' => round($ptPricePerGram * 31.1035, 2),
                'price_gram' => $ptPricePerGram,
                'change_oz' => 0.0,
                'change_pct' => 0.0,
                'direction' => 'flat',
            ],
            [
                'key' => 'palladium',
                'name_en' => 'Palladium',
                'symbol' => 'Pd',
                'price_oz' => round($pdPricePerGram * 31.1035, 2),
                'price_gram' => $pdPricePerGram,
                'change_oz' => 0.0,
                'change_pct' => 0.0,
                'direction' => 'flat',
            ],
            [
                'key' => 'rhodium',
                'name_en' => 'Rhodium',
                'symbol' => 'Rh',
                'price_oz' => round($rhPricePerGram * 31.1035, 2),
                'price_gram' => $rhPricePerGram,
                'change_oz' => 0.0,
                'change_pct' => 0.0,
                'direction' => 'flat',
            ],
        ],
    ];

    $mock = \Mockery::mock(MetalsSpotService::class);
    $mock->shouldReceive('all')->andReturn($spot);
    app()->instance(MetalsSpotService::class, $mock);
}

function mobileApiPngPath(): string
{
    $base = tempnam(sys_get_temp_dir(), 'mobile_api_item_');

    if ($base === false) {
        throw new RuntimeException('Failed to create a temporary image file.');
    }

    @unlink($base);

    $path = $base.'.png';

    $image = imagecreatetruecolor(48, 48);
    $background = imagecolorallocate($image, 232, 232, 232);
    $accent = imagecolorallocate($image, 92, 92, 92);

    imagefill($image, 0, 0, $background);
    imagefilledellipse($image, 24, 24, 30, 30, $accent);

    imagepng($image, $path);
    imagedestroy($image);

    return $path;
}

function mobileApiAttachImage(Item $item): string
{
    $path = mobileApiPngPath();
    $item->addMedia($path)->toMediaCollection('images');

    return $path;
}

beforeEach(function (): void {
    Storage::fake('public');
});

uses(RefreshDatabase::class);

test('search endpoint accepts text and categoryId', function () {
    mockItemMetalsSpotServiceAscii();

    $toyota = CarGroup::factory()->create(['name' => 'TOYOTA']);
    $lexus = CarGroup::factory()->create(['name' => 'LEXUS']);

    $matching = Item::factory()->create([
        'car_group_id' => $toyota->id,
        'serial_code' => '42004-9842',
        'model' => 'PRIUS HYBRID',
    ]);

    $imagePath = mobileApiAttachImage($matching);

    try {
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
        expect($response->json('data.0.imageUrl'))->toBeString()->not->toBe('');
    } finally {
        @unlink($imagePath);
    }
});

test('item search endpoint accepts text and carGroup', function () {
    mockItemMetalsSpotServiceAscii();

    $opel = CarGroup::factory()->create(['name' => 'OPEL', 'excel_sheet_name' => 'OPEL']);
    $japan = CarGroup::factory()->create(['name' => 'JAPAN', 'excel_sheet_name' => 'JAPAN']);

    $matching = Item::factory()->create([
        'car_group_id' => $opel->id,
        'serial_code' => 'GM10',
        'model' => 'VAUXHAL',
    ]);

    $imagePath = mobileApiAttachImage($matching);

    try {
        Item::factory()->create([
            'car_group_id' => $japan->id,
            'serial_code' => 'GM10',
            'model' => 'TOYOTA PRIUS',
        ]);

        $response = getJson('/api/items?text=GM10&carGroup=OPEL');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matching->id);
        expect($response->json('data.0.imageUrl'))->toBeString()->not->toBe('');
    } finally {
        @unlink($imagePath);
    }
});

test('item collections return only calculable items with at least one image', function () {
    mockItemMetalsSpotServiceAscii();

    $group = CarGroup::factory()->create();

    $calculable = Item::factory()->create([
        'car_group_id' => $group->id,
        'weight_kg' => 1.5,
        'pt_ppm' => 1200,
        'pd_ppm' => 450,
        'rh_ppm' => 90,
    ]);

    $imagePath = mobileApiAttachImage($calculable);
    $hiddenCalculable = Item::factory()->create([
        'car_group_id' => $group->id,
        'weight_kg' => 1.5,
        'pt_ppm' => 1200,
        'pd_ppm' => 450,
        'rh_ppm' => 90,
    ]);

    try {
        $missingPriceData = Item::factory()->create([
            'car_group_id' => $group->id,
            'weight_kg' => null,
            'pt_ppm' => null,
            'pd_ppm' => null,
            'rh_ppm' => null,
        ]);

        $response = getJson('/api/items');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $calculable->id);
        $response->assertJsonPath('data.0.price', 35.55);
        expect($response->json('data.0.imageUrl'))->toBeString()->not->toBe('');
        expect($response->json('data.0.imageThumbUrl'))->toBeString()->not->toBe('');
        expect($response->json('data.0.imageDetailUrl'))->toBeString()->not->toBe('');
        $response->assertJsonMissing(['id' => $hiddenCalculable->id]);
        $response->assertJsonMissing(['id' => $missingPriceData->id]);

        $homeResponse = getJson('/api/home/top_items');

        $homeResponse->assertOk();
        $homeResponse->assertJsonCount(1, 'topItems');
        $homeResponse->assertJsonPath('topItems.0.id', $calculable->id);
        $homeResponse->assertJsonPath('topItems.0.price', 35.55);
        expect($homeResponse->json('topItems.0.imageUrl'))->toBeString()->not->toBe('');
    } finally {
        @unlink($imagePath);
    }
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
    $response->assertJsonCount(3, 'stats.changes');
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

test('calculator endpoint accepts percent-fraction rate payloads', function () {
    MetalPrice::factory()->create([
        'pt_usd_per_oz' => 1996.00,
        'pd_usd_per_oz' => 1409.00,
        'rh_usd_per_oz' => 9450.00,
        'fetched_at' => now(),
    ]);

    $response = postJson('/api/calculator/estimate', [
        'weight' => 182,
        'weightUnit' => 'g',
        'ptPpm' => 120,
        'pdPpm' => 350,
        'rhPpm' => 12,
        'ptUsdPerGram' => 64.17,
        'pdUsdPerGram' => 45.30,
        'rhUsdPerGram' => 303.82,
        'ptRate' => 0.0098,
        'pdRate' => 0.0098,
        'rhRate' => 0.009,
        'humidityRate' => 5,
        'currency' => 'USD',
    ]);

    $response->assertOk();
    $response->assertJsonPath('estimate.inputs.ptRate', 0.98);
    $response->assertJsonPath('estimate.inputs.humidityRate', 0.05);
    $response->assertJsonPath('estimate.totalUsd', 4.56);
});

test('details endpoint returns converter and related entries', function () {
    mockItemMetalsSpotServiceAscii();

    $group = CarGroup::factory()->create();

    $target = Item::factory()->create(['car_group_id' => $group->id]);
    $related = Item::factory()->create(['car_group_id' => $group->id]);
    $targetImagePath = mobileApiAttachImage($target);
    $relatedImagePath = mobileApiAttachImage($related);

    try {
        $response = getJson("/api/items/{$target->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $target->id);
        $response->assertJsonPath('data.carGroup.id', $group->id);
        expect($response->json('data.imageUrl'))->toBeString()->not->toBe('');
        $response->assertJsonPath('related.0.id', $related->id);
        expect($response->json('related.0.imageUrl'))->toBeString()->not->toBe('');
    } finally {
        @unlink($targetImagePath);
        @unlink($relatedImagePath);
    }
});

test('item details endpoint returns camelCase converter payload', function () {
    mockItemMetalsSpotServiceAscii();

    $converter = Item::factory()->create();
    $converterImagePath = mobileApiAttachImage($converter);

    try {
        $response = getJson("/api/items/{$converter->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $converter->id);
        $response->assertJsonPath('data.serialCode', $converter->normalized_serial);
        expect($response->json('data.imageUrl'))->toBeString()->not->toBe('');
    } finally {
        @unlink($converterImagePath);
    }
});

test('similar items endpoint returns same car group items', function () {
    mockItemMetalsSpotServiceAscii();

    $group = CarGroup::factory()->create();

    $target = Item::factory()->create(['car_group_id' => $group->id]);
    $similar = Item::factory()->count(3)->create(['car_group_id' => $group->id]);
    $targetImagePath = mobileApiAttachImage($target);
    $similarImagePaths = $similar->map(fn (Item $item): string => mobileApiAttachImage($item))->all();
    Item::factory()->create();

    try {
        $response = getJson("/api/items/{$target->id}/similar");

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('data.0.carGroup.id', $group->id);
        $response->assertJsonMissing(['id' => $target->id]);
        $response->assertJsonFragment(['id' => $similar[0]->id]);
        $response->assertJsonFragment(['id' => $similar[1]->id]);
        $response->assertJsonFragment(['id' => $similar[2]->id]);
        expect($response->json('data.0.imageUrl'))->toBeString()->not->toBe('');
    } finally {
        @unlink($targetImagePath);

        foreach ($similarImagePaths as $path) {
            @unlink($path);
        }
    }
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
