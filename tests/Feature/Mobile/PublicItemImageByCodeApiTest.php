<?php

use App\Models\CarGroup;
use App\Models\ExtraCode;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\post;

function publicItemImageByCodeAttachImage(Item $item): void
{
    $path = tempnam(sys_get_temp_dir(), 'public_item_image_');

    if ($path === false) {
        throw new RuntimeException('Failed to create a temporary item image.');
    }

    $pngPath = $path.'.png';
    @unlink($path);

    $image = imagecreatetruecolor(48, 48);
    $background = imagecolorallocate($image, 220, 220, 220);
    imagefill($image, 0, 0, $background);
    imagepng($image, $pngPath);
    imagedestroy($image);

    $item->addMedia($pngPath)->toMediaCollection('images');
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

test('public endpoint replaces images for every item matching the normalized serial or extra code', function (): void {
    $group = CarGroup::factory()->create();

    $serialMatch = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'GM10-ABC',
    ]);
    publicItemImageByCodeAttachImage($serialMatch);

    $extraCodeMatch = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'OTHER-001',
    ]);
    ExtraCode::factory()->create([
        'item_id' => $extraCodeMatch->id,
        'code' => 'GM10.ABC',
    ]);
    publicItemImageByCodeAttachImage($extraCodeMatch);

    $unrelated = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'GM10-ABC-2',
    ]);
    publicItemImageByCodeAttachImage($unrelated);
    $unrelatedMediaId = $unrelated->getFirstMedia('images')?->getKey();

    $response = post('/api/items/images/by-code', [
        'code' => 'gm10 abc',
        'image' => UploadedFile::fake()->image('replacement.jpg', 900, 700),
    ]);

    $response->assertOk();
    $response->assertJsonPath('normalized_code', 'GM10ABC');
    $response->assertJsonPath('updated_count', 2);

    foreach ([$serialMatch, $extraCodeMatch] as $item) {
        $item->refresh();
        $media = $item->getFirstMedia('images');

        expect($media)->not->toBeNull();
        expect($media->generated_conversions)->toMatchArray([
            'thumb' => true,
            'card' => true,
            'detail' => true,
        ]);
        expect($item->image_url)->not->toBeNull();
        expect($item->image_thumb_url)->not->toBeNull();
        expect($item->image_detail_url)->not->toBeNull();
    }

    $unrelated->refresh();
    expect($unrelated->getFirstMedia('images')?->getKey())->toBe($unrelatedMediaId);
});

test('public endpoint returns not found when no item has the provided code', function (): void {
    $response = post('/api/items/images/by-code', [
        'code' => 'DOES-NOT-EXIST',
        'image' => UploadedFile::fake()->image('replacement.jpg', 400, 400),
    ]);

    $response->assertNotFound();
    $response->assertJsonPath('updated_count', 0);
});

test('public endpoint validates the image upload', function (): void {
    $response = post('/api/items/images/by-code', [
        'code' => 'GM10-ABC',
        'image' => UploadedFile::fake()->create('not-an-image.txt', 10, 'text/plain'),
    ], [
        'Accept' => 'application/json',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('image');
});
