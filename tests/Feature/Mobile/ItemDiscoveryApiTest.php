<?php

use App\Models\CarGroup;
use App\Models\ExtraCode;
use App\Models\Item;
use App\Models\ItemFilterMapping;
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

    $image = imagecreatetruecolor(48, 48);
    $background = imagecolorallocate($image, 232, 232, 232);
    $accent = imagecolorallocate($image, 92, 92, 92);

    imagefill($image, 0, 0, $background);
    imagefilledellipse($image, 24, 24, 30, 30, $accent);
    imagepng($image, $pngPath);
    imagedestroy($image);

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

test('needs review items are blocked from item discovery APIs until the review is resolved', function (): void {
    $group = CarGroup::factory()->create(['name' => 'MERCEDES']);

    $blocked = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'KT 6044',
        'normalized_serial' => 'KT6044',
    ]);
    itemDiscoveryApiAttachImage($blocked);

    ItemFilterMapping::factory()->create([
        'item_id' => $blocked->id,
        'status' => ItemFilterMapping::STATUS_NEEDS_REVIEW,
        'evidence' => ['pricing_review' => ['type' => 'component_weight']],
    ]);

    $visible = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'KT 6043',
        'normalized_serial' => 'KT6043',
    ]);
    itemDiscoveryApiAttachImage($visible);

    ItemFilterMapping::factory()->create([
        'item_id' => $visible->id,
        'status' => ItemFilterMapping::STATUS_APPROVED,
    ]);

    $items = getJson('/api/items?sort=serial_code');

    $items->assertOk();
    $items->assertJsonPath('meta.total', 1);
    $items->assertJsonPath('data.0.serialCode', 'KT6043');
    $items->assertJsonMissing(['serialCode' => 'KT6044']);

    $codes = getJson('/api/items/codes?search=KT60&limit=10');

    $codes->assertOk();
    $codes->assertJsonMissing(['KT 6044']);

    getJson("/api/items/{$blocked->id}")->assertNotFound();
    getJson("/api/items/{$visible->id}")->assertOk();

    $blockedMapping = $blocked->filterMapping()->firstOrFail();
    $blockedMapping->update(['status' => ItemFilterMapping::STATUS_IGNORED]);

    getJson("/api/items/{$blocked->id}")->assertOk();
});
