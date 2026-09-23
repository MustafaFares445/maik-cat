<?php

use App\Models\Item;
use App\Services\ItemDashboardImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function dashboardItemImagePngBytes(int $width = 360, int $height = 240): string
{
    $image = imagecreatetruecolor($width, $height);

    if ($image === false) {
        throw new RuntimeException('Failed to create dashboard item image test fixture.');
    }

    $background = imagecolorallocate($image, 245, 245, 245);
    $product = imagecolorallocate($image, 90, 90, 90);

    imagefill($image, 0, 0, $background);
    imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), 220, 100, $product);

    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

test('dashboard item image upload receives maik cat watermark and is stored in media library', function (): void {
    Storage::fake('public');

    $item = Item::factory()->create();
    $uploadPath = 'filament/items/dashboard-upload.png';

    Storage::disk('public')->put($uploadPath, dashboardItemImagePngBytes());
    $beforeHash = md5_file(Storage::disk('public')->path($uploadPath));

    $media = app(ItemDashboardImageService::class)
        ->replaceFromPublicUpload($item, $uploadPath);

    expect($media)->not->toBeNull()
        ->and($media?->collection_name)->toBe('images')
        ->and($media?->getCustomProperty('source'))->toBe('dashboard')
        ->and($media?->getCustomProperty('watermark_mode'))->toBe('spatie')
        ->and($media?->getCustomProperty('maikcat_watermark'))->toBeTrue();

    $storedPath = $media?->getPath();

    expect($storedPath)->not->toBeNull()
        ->and(is_file((string) $storedPath))->toBeTrue()
        ->and(md5_file((string) $storedPath))->not->toBe($beforeHash)
        ->and($item->fresh()->media()->where('collection_name', 'images')->count())->toBe(1);
});

test('saving an unchanged existing dashboard image does not watermark or duplicate it again', function (): void {
    Storage::fake('public');

    $item = Item::factory()->create();
    $uploadPath = 'filament/items/dashboard-existing.png';

    Storage::disk('public')->put($uploadPath, dashboardItemImagePngBytes());

    $service = app(ItemDashboardImageService::class);
    $media = $service->replaceFromPublicUpload($item, $uploadPath);

    expect($media)->not->toBeNull();

    $relativePath = $media?->getPathRelativeToRoot();
    $beforeHash = md5_file((string) $media?->getPath());

    $sameMedia = $service->replaceFromPublicUpload($item->fresh(), $relativePath);

    expect($sameMedia?->getKey())->toBe($media?->getKey())
        ->and($item->fresh()->media()->where('collection_name', 'images')->count())->toBe(1)
        ->and(md5_file((string) $sameMedia?->getPath()))->toBe($beforeHash);
});
