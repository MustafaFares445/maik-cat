<?php

use App\Models\CarGroup;
use App\Models\ExtraCode;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

function publicItemImageByCodeAttachImage(Item $item, int $gray = 220): Media
{
    $path = tempnam(sys_get_temp_dir(), 'public_item_image_');

    if ($path === false) {
        throw new RuntimeException('Failed to create a temporary item image.');
    }

    $pngPath = $path.'.png';
    @unlink($path);

    $image = imagecreatetruecolor(48, 48);
    $gray = max(0, min(255, $gray));
    $background = imagecolorallocate($image, $gray, $gray, $gray);
    imagefill($image, 0, 0, $background);
    imagepng($image, $pngPath);
    imagedestroy($image);

    return $item->addMedia($pngPath)->toMediaCollection('images');
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

test('public media range endpoint uses accepted images to update matching items one by one', function (): void {
    $group = CarGroup::factory()->create();

    $serialTarget = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'CAT 7026',
    ]);
    $serialTargetOldMedia = publicItemImageByCodeAttachImage($serialTarget, 25);

    $extraCodeTarget = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'OTHER-7026',
    ]);
    ExtraCode::factory()->create([
        'item_id' => $extraCodeTarget->id,
        'code' => 'CAT/7026',
    ]);
    $extraTargetOldMedia = publicItemImageByCodeAttachImage($extraCodeTarget, 45);

    $unrelated = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'CAT-7026-X',
    ]);
    $unrelatedMedia = publicItemImageByCodeAttachImage($unrelated, 65);

    $firstAccepted = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'CAT-7026',
    ]);
    $firstAcceptedMedia = publicItemImageByCodeAttachImage($firstAccepted, 210);

    $secondAccepted = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'CAT.7026',
    ]);
    $secondAcceptedMedia = publicItemImageByCodeAttachImage($secondAccepted, 120);

    $firstAcceptedHash = md5_file($firstAcceptedMedia->getPath());
    $secondAcceptedHash = md5_file($secondAcceptedMedia->getPath());

    $response = postJson('/api/items/images/sync-by-media-range', [
        'from' => (int) $firstAcceptedMedia->getKey(),
        'to' => (int) $secondAcceptedMedia->getKey(),
    ]);

    $response->assertOk();
    $response->assertJsonPath('source_count', 2);
    $response->assertJsonPath('processed_source_count', 2);
    $response->assertJsonPath('updated_count', 2);
    $response->assertJsonPath('results.0.media_id', $firstAcceptedMedia->getKey());
    $response->assertJsonPath('results.0.updated_count', 2);
    $response->assertJsonPath('results.1.media_id', $secondAcceptedMedia->getKey());
    $response->assertJsonPath('results.1.updated_count', 0);

    foreach ([$serialTarget, $extraCodeTarget] as $target) {
        $target->refresh();
        $media = $target->getFirstMedia('images');

        expect($media)->not->toBeNull();
        expect($media->generated_conversions)->toMatchArray([
            'thumb' => true,
            'card' => true,
            'detail' => true,
        ]);
        expect(md5_file($media->getPath()))->toBe($firstAcceptedHash);
    }

    $serialTarget->refresh();
    $extraCodeTarget->refresh();
    expect($serialTarget->getFirstMedia('images')?->getKey())->not->toBe($serialTargetOldMedia->getKey());
    expect($extraCodeTarget->getFirstMedia('images')?->getKey())->not->toBe($extraTargetOldMedia->getKey());

    $firstAccepted->refresh();
    $secondAccepted->refresh();
    expect($firstAccepted->getFirstMedia('images')?->getKey())->toBe($firstAcceptedMedia->getKey());
    expect($secondAccepted->getFirstMedia('images')?->getKey())->toBe($secondAcceptedMedia->getKey());
    expect(md5_file($firstAccepted->getFirstMedia('images')->getPath()))->toBe($firstAcceptedHash);
    expect(md5_file($secondAccepted->getFirstMedia('images')->getPath()))->toBe($secondAcceptedHash);

    $unrelated->refresh();
    expect($unrelated->getFirstMedia('images')?->getKey())->toBe($unrelatedMedia->getKey());
});

test('public media range endpoint returns not found when the range has no accepted item media', function (): void {
    $response = postJson('/api/items/images/sync-by-media-range', [
        'from' => 900000,
        'to' => 900100,
    ]);

    $response->assertNotFound();
    $response->assertJsonPath('source_count', 0);
    $response->assertJsonPath('updated_count', 0);
});

test('public media range endpoint validates that to is not before from', function (): void {
    $response = postJson('/api/items/images/sync-by-media-range', [
        'from' => 7054,
        'to' => 7026,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('to');
});
