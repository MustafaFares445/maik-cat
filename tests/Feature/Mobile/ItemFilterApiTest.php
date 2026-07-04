<?php

use App\Models\CarGroup;
use App\Models\Item;
use App\Services\Mobile\MetalsSpotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;

function itemFilterApiMockMetalsSpotService(): void
{
    $spot = [
        'source' => 'test',
        'cached' => false,
        'currency' => 'USD',
        'fx_rate' => 1.0,
        'updated_at' => now()->toIso8601String(),
        'data' => [
            ['key' => 'platinum', 'price_gram' => 10.0],
            ['key' => 'palladium', 'price_gram' => 20.0],
            ['key' => 'rhodium', 'price_gram' => 30.0],
        ],
    ];

    $mock = Mockery::mock(MetalsSpotService::class);
    $mock->shouldReceive('all')->andReturn($spot);

    app()->instance(MetalsSpotService::class, $mock);
}

function itemFilterApiPngPath(): string
{
    $base = tempnam(sys_get_temp_dir(), 'item_filter_api_');

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

function itemFilterApiAttachImage(Item $item): string
{
    $path = itemFilterApiPngPath();
    $item->addMedia($path)->toMediaCollection('images');

    return $path;
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    itemFilterApiMockMetalsSpotService();
});

test('items endpoint applies top-level camel case categoryId filter', function (): void {
    $targetGroup = CarGroup::factory()->create(['name' => 'TOYOTA']);
    $otherGroup = CarGroup::factory()->create(['name' => 'LEXUS']);

    $matching = Item::factory()->create([
        'car_group_id' => $targetGroup->id,
        'serial_code' => 'FILTER-TOYOTA',
        'model' => 'PRIUS',
    ]);

    $other = Item::factory()->create([
        'car_group_id' => $otherGroup->id,
        'serial_code' => 'FILTER-LEXUS',
        'model' => 'RX',
    ]);

    $matchingImagePath = itemFilterApiAttachImage($matching);
    $otherImagePath = itemFilterApiAttachImage($other);

    try {
        $response = getJson("/api/items?categoryId={$targetGroup->id}&perPage=20&sort=-created_at");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matching->id);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonMissing(['id' => $other->id]);
    } finally {
        @unlink($matchingImagePath);
        @unlink($otherImagePath);
    }
});

test('items endpoint applies nested camel case categoryId filter', function (): void {
    $targetGroup = CarGroup::factory()->create(['name' => 'BMW']);
    $otherGroup = CarGroup::factory()->create(['name' => 'AUDI']);

    $matching = Item::factory()->create([
        'car_group_id' => $targetGroup->id,
        'serial_code' => 'FILTER-BMW',
        'model' => 'M3',
    ]);

    $other = Item::factory()->create([
        'car_group_id' => $otherGroup->id,
        'serial_code' => 'FILTER-AUDI',
        'model' => 'A4',
    ]);

    $matchingImagePath = itemFilterApiAttachImage($matching);
    $otherImagePath = itemFilterApiAttachImage($other);

    try {
        $query = http_build_query([
            'filter' => ['categoryId' => $targetGroup->id],
            'perPage' => 20,
        ]);

        $response = getJson("/api/items?{$query}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matching->id);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonMissing(['id' => $other->id]);
    } finally {
        @unlink($matchingImagePath);
        @unlink($otherImagePath);
    }
});
