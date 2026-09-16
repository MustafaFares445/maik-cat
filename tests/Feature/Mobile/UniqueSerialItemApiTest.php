<?php

use App\Models\Item;
use App\Services\Mobile\ItemApiSettingsService;
use App\Services\Mobile\MetalsSpotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;

function uniqueSerialApiImagePath(): string
{
    $base = tempnam(sys_get_temp_dir(), 'unique_serial_api_');

    if ($base === false) {
        throw new RuntimeException('Failed to create a temporary image file.');
    }

    @unlink($base);

    $path = $base.'.png';
    $image = imagecreatetruecolor(32, 32);
    $background = imagecolorallocate($image, 230, 230, 230);

    imagefill($image, 0, 0, $background);
    imagepng($image, $path);
    imagedestroy($image);

    return $path;
}

function uniqueSerialApiAttachImage(Item $item): string
{
    $path = uniqueSerialApiImagePath();
    $item->addMedia($path)->toMediaCollection('images');

    return $path;
}

function mockUniqueSerialMetalsSpotService(): void
{
    $mock = Mockery::mock(MetalsSpotService::class);
    $mock->shouldReceive('all')->andReturn([
        'source' => 'test',
        'cached' => false,
        'currency' => 'USD',
        'fx_rate' => 1.0,
        'updated_at' => now()->toIso8601String(),
        'data' => [
            ['key' => 'platinum', 'price_oz' => 10.0 * 31.1043, 'price_gram' => 10.0],
            ['key' => 'palladium', 'price_oz' => 20.0 * 31.1043, 'price_gram' => 20.0],
            ['key' => 'rhodium', 'price_oz' => 30.0 * 31.1043, 'price_gram' => 30.0],
        ],
    ]);

    app()->instance(MetalsSpotService::class, $mock);
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    mockUniqueSerialMetalsSpotService();
});

test('unique serial response mode is disabled by default', function (): void {
    $first = Item::factory()->create([
        'serial_code' => 'GM-10',
        'weight_kg' => 1.0,
        'pt_ppm' => 100,
        'pd_ppm' => 10,
        'rh_ppm' => 1,
    ]);
    $second = Item::factory()->create([
        'serial_code' => 'GM 10',
        'weight_kg' => 3.0,
        'pt_ppm' => 300,
        'pd_ppm' => 30,
        'rh_ppm' => 3,
    ]);

    $paths = [
        uniqueSerialApiAttachImage($first),
        uniqueSerialApiAttachImage($second),
    ];

    try {
        $response = getJson('/api/items?sort=serial_code');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.total', 2);
    } finally {
        foreach ($paths as $path) {
            @unlink($path);
        }
    }
});

test('unique serial mode returns the arithmetic mean of individual prices without averaging assay fields', function (): void {
    app(ItemApiSettingsService::class)->updateUniqueSerialItems(true);

    $first = Item::factory()->create([
        'serial_code' => 'GM-10',
        'weight_kg' => 1.0,
        'pt_ppm' => 100,
        'pd_ppm' => 10,
        'rh_ppm' => 1,
    ]);
    $second = Item::factory()->create([
        'serial_code' => 'GM 10',
        'weight_kg' => 2.0,
        'pt_ppm' => 200,
        'pd_ppm' => 20,
        'rh_ppm' => 2,
    ]);
    $hiddenThird = Item::factory()->create([
        'serial_code' => 'GM.10',
        'weight_kg' => 9.0,
        'pt_ppm' => 300,
        'pd_ppm' => 30,
        'rh_ppm' => 3,
    ]);
    $other = Item::factory()->create([
        'serial_code' => 'ZZZ-20',
        'weight_kg' => 4.0,
        'pt_ppm' => 400,
        'pd_ppm' => 40,
        'rh_ppm' => 4,
    ]);

    $paths = [
        uniqueSerialApiAttachImage($first),
        uniqueSerialApiAttachImage($second),
        uniqueSerialApiAttachImage($other),
    ];

    try {
        $pageOne = getJson('/api/items?sort=serial_code&per_page=1');

        $pageOne->assertOk();
        $pageOne->assertJsonCount(1, 'data');
        $pageOne->assertJsonPath('meta.total', 2);
        $pageOne->assertJsonPath('meta.lastPage', 2);
        $pageOne->assertJsonPath('data.0.serialCode', 'GM10');
        $pageOne->assertJsonPath('data.0.price', 10.5);

        expect((float) $pageOne->json('data.0.weightKg'))->toBeIn([1.0, 2.0])
            ->and((float) $pageOne->json('data.0.weightKg'))->not->toBe(4.0);

        $pageTwo = getJson('/api/items?sort=serial_code&per_page=1&page=2');

        $pageTwo->assertOk();
        $pageTwo->assertJsonCount(1, 'data');
        $pageTwo->assertJsonPath('data.0.serialCode', 'ZZZ20');

        $detail = getJson("/api/items/{$first->id}");

        $detail->assertOk();
        $detail->assertJsonPath('data.serialCode', 'GM10');
        $detail->assertJsonPath('data.price', 10.5);
        $detail->assertJsonPath('data.weightKg', 1);

        expect((float) $first->fresh()->weight_kg)->toBe(1.0)
            ->and((float) $first->fresh()->pt_ppm)->toBe(100.0)
            ->and((float) $second->fresh()->weight_kg)->toBe(2.0)
            ->and((float) $hiddenThird->fresh()->weight_kg)->toBe(9.0);
    } finally {
        foreach ($paths as $path) {
            @unlink($path);
        }
    }
});
