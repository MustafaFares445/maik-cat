<?php

use App\Models\CarGroup;
use App\Models\ExtraCode;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;

function itemDiscoveryApiAttachImage(Item $item): void
{
    $path = tempnam(sys_get_temp_dir(), 'item_discovery_');

    if ($path === false) {
        throw new RuntimeException('Failed to create a temporary item image.');
    }

    $pngPath = $path.'.png';
    @unlink($path);

    file_put_contents(
        $pngPath,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z7j8AAAAASUVORK5CYII=', true),
    );

    $item->addMedia($pngPath)->toMediaCollection('images');
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

test('car groups endpoint returns only categories containing API-visible items', function (): void {
    $visibleGroup = CarGroup::factory()->create(['name' => 'BMW']);
    $incompleteGroup = CarGroup::factory()->create(['name' => 'EMPTY ITEMS']);
    CarGroup::factory()->create(['name' => 'NO ITEMS']);

    $visibleItem = Item::factory()->create([
        'car_group_id' => $visibleGroup->id,
    ]);
    itemDiscoveryApiAttachImage($visibleItem);

    Item::factory()->create([
        'car_group_id' => $incompleteGroup->id,
    ]);

    $response = getJson('/api/car_groups');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $visibleGroup->id);
    $response->assertJsonMissing(['id' => $incompleteGroup->id]);
});

test('item code suggestions return only matching codes from API-visible items', function (): void {
    $group = CarGroup::factory()->create();

    $visibleItem = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'GM10-ABC',
        'normalized_serial' => 'GM10ABC',
    ]);
    itemDiscoveryApiAttachImage($visibleItem);

    ExtraCode::factory()->create([
        'item_id' => $visibleItem->id,
        'code' => 'ALT-GM10',
    ]);

    $hiddenItem = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'GM10-HIDDEN',
        'normalized_serial' => 'GM10HIDDEN',
    ]);

    ExtraCode::factory()->create([
        'item_id' => $hiddenItem->id,
        'code' => 'GM10-HIDDEN-ALT',
    ]);

    $response = getJson('/api/items/codes?search=GM10&limit=10');

    $response->assertOk();
    $response->assertExactJson([
        'data' => [
            'GM10-ABC',
            'ALT-GM10',
        ],
    ]);
});
