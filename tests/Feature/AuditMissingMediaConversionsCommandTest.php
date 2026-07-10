<?php

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function auditMissingConversionsTempImage(): string
{
    $base = tempnam(sys_get_temp_dir(), 'media_conversion_audit_');

    if ($base === false) {
        throw new RuntimeException('Failed to allocate a temporary image path.');
    }

    @unlink($base);

    $path = $base.'.png';

    $image = imagecreatetruecolor(320, 220);
    $background = imagecolorallocate($image, 245, 245, 245);
    $shape = imagecolorallocate($image, 120, 120, 120);

    imagefill($image, 0, 0, $background);
    imagefilledellipse($image, 160, 110, 220, 90, $shape);

    ob_start();
    imagepng($image);
    file_put_contents($path, (string) ob_get_clean());
    imagedestroy($image);

    return $path;
}

test('media repair regenerates missing item image conversions', function () {
    Storage::fake('public');

    $item = Item::factory()->create([
        'weight_kg' => 1.2,
        'pt_ppm' => 1100,
        'pd_ppm' => 0,
        'rh_ppm' => 0,
    ]);
    $imagePath = auditMissingConversionsTempImage();
    $media = $item
        ->addMedia($imagePath)
        ->usingFileName('missing-conversion-audit.png')
        ->toMediaCollection('images');

    $thumbRelativePath = $media->getPathRelativeToRoot('thumb');
    Storage::disk('public')->delete($thumbRelativePath);

    expect($media->hasGeneratedConversion('thumb'))->toBeTrue();
    expect(Storage::disk('public')->exists($thumbRelativePath))->toBeFalse();

    $this->artisan('media:audit-missing-conversions', [
        '--model' => Item::class,
        '--collection' => 'images',
    ])
        ->expectsOutputToContain('Media scanned: 1')
        ->assertExitCode(0);

    expect(Storage::disk('public')->exists($thumbRelativePath))->toBeTrue();

    @unlink($imagePath);
});

test('media repair skips item images when no conversions are missing', function () {
    Storage::fake('public');

    $item = Item::factory()->create([
        'weight_kg' => 1.2,
        'pt_ppm' => 1100,
        'pd_ppm' => 0,
        'rh_ppm' => 0,
    ]);
    $imagePath = auditMissingConversionsTempImage();
    $item->addMedia($imagePath)->usingFileName('complete-conversion-audit.png')->toMediaCollection('images');

    $this->artisan('media:audit-missing-conversions', [
        '--model' => Item::class,
        '--collection' => 'images',
    ])
        ->expectsOutputToContain('Media scanned: 1')
        ->expectsOutputToContain('Media repaired: 0')
        ->expectsOutputToContain('Conversions regenerated: 0')
        ->expectsOutputToContain('No missing conversions were found.')
        ->assertExitCode(0);

    @unlink($imagePath);
});
