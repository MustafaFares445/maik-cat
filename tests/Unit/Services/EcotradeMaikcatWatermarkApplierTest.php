<?php

use App\Services\Ecotrade\EcotradeMaikcatWatermarkApplier;

function ecotradeWatermarkTempPng(int $width = 360, int $height = 240): string
{
    $image = imagecreatetruecolor($width, $height);

    if ($image === false) {
        throw new RuntimeException('Failed to create a temporary image for watermark testing.');
    }

    $background = imagecolorallocate($image, 242, 242, 242);
    $accent = imagecolorallocate($image, 128, 128, 128);

    imagefill($image, 0, 0, $background);
    imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width * 0.6), (int) ($height * 0.35), $accent);

    $path = tempnam(sys_get_temp_dir(), 'ecotrade_watermark_');

    if ($path === false) {
        imagedestroy($image);

        throw new RuntimeException('Failed to allocate a temporary path for watermark testing.');
    }

    $target = $path.'.png';
    @unlink($path);

    ob_start();
    imagepng($image);
    file_put_contents($target, (string) ob_get_clean());
    imagedestroy($image);

    return $target;
}

function ecotradeWatermarkSolidPng(int $width = 360, int $height = 240): string
{
    $image = imagecreatetruecolor($width, $height);

    if ($image === false) {
        throw new RuntimeException('Failed to create a temporary image for watermark testing.');
    }

    $background = imagecolorallocate($image, 180, 32, 24);
    imagefill($image, 0, 0, $background);

    $path = tempnam(sys_get_temp_dir(), 'ecotrade_watermark_solid_');

    if ($path === false) {
        imagedestroy($image);

        throw new RuntimeException('Failed to allocate a temporary path for watermark testing.');
    }

    $target = $path.'.png';
    @unlink($path);

    ob_start();
    imagepng($image);
    file_put_contents($target, (string) ob_get_clean());
    imagedestroy($image);

    return $target;
}

function ecotradeWatermarkWhitePng(int $width = 360, int $height = 240): string
{
    $image = imagecreatetruecolor($width, $height);

    if ($image === false) {
        throw new RuntimeException('Failed to create a temporary image for watermark testing.');
    }

    $background = imagecolorallocate($image, 255, 255, 255);
    imagefill($image, 0, 0, $background);

    $path = tempnam(sys_get_temp_dir(), 'ecotrade_watermark_white_');

    if ($path === false) {
        imagedestroy($image);

        throw new RuntimeException('Failed to allocate a temporary path for watermark testing.');
    }

    $target = $path.'.png';
    @unlink($path);

    ob_start();
    imagepng($image);
    file_put_contents($target, (string) ob_get_clean());
    imagedestroy($image);

    return $target;
}

/**
 * @return array{red: float, green: float, blue: float}
 */
function ecotradeWatermarkAverageRgb(string $path): array
{
    $image = imagecreatefrompng($path);

    if ($image === false) {
        throw new RuntimeException('Failed to inspect watermarked image.');
    }

    $red = 0;
    $green = 0;
    $blue = 0;
    $count = 0;

    for ($y = 0; $y < imagesy($image); $y += 12) {
        for ($x = 0; $x < imagesx($image); $x += 12) {
            $color = imagecolorat($image, $x, $y);
            $red += ($color >> 16) & 0xFF;
            $green += ($color >> 8) & 0xFF;
            $blue += $color & 0xFF;
            $count++;
        }
    }

    imagedestroy($image);

    return [
        'red' => $red / $count,
        'green' => $green / $count,
        'blue' => $blue / $count,
    ];
}

function ecotradeWatermarkDarkSampleCount(string $path): int
{
    $image = imagecreatefrompng($path);

    if ($image === false) {
        throw new RuntimeException('Failed to inspect watermarked image.');
    }

    $count = 0;

    for ($y = 0; $y < imagesy($image); $y += 10) {
        for ($x = 0; $x < imagesx($image); $x += 10) {
            $color = imagecolorat($image, $x, $y);
            $red = ($color >> 16) & 0xFF;
            $green = ($color >> 8) & 0xFF;
            $blue = $color & 0xFF;

            if ($red < 245 || $green < 245 || $blue < 245) {
                $count++;
            }
        }
    }

    imagedestroy($image);

    return $count;
}

test('maikcat watermark applier uses the bundled watermark image asset', function () {
    $sourcePath = ecotradeWatermarkTempPng();
    $beforeHash = md5_file($sourcePath);
    $beforeSize = getimagesize($sourcePath);

    expect(is_file(resource_path('images/ecotrade/maikcat-watermark.png')))->toBeTrue();

    app(EcotradeMaikcatWatermarkApplier::class)->apply($sourcePath);

    $afterHash = md5_file($sourcePath);
    $afterSize = getimagesize($sourcePath);

    expect($afterHash)->not->toBe($beforeHash)
        ->and($afterSize[0])->toBe($beforeSize[0])
        ->and($afterSize[1])->toBe($beforeSize[1]);

    @unlink($sourcePath);
});

test('maikcat watermark stays visible on a light background', function () {
    $sourcePath = ecotradeWatermarkWhitePng();

    app(EcotradeMaikcatWatermarkApplier::class)->apply($sourcePath);

    expect(ecotradeWatermarkDarkSampleCount($sourcePath))->toBeGreaterThan(0);

    @unlink($sourcePath);
});

test('maikcat watermark keeps the product image visible', function () {
    $sourcePath = ecotradeWatermarkSolidPng();

    app(EcotradeMaikcatWatermarkApplier::class)->apply($sourcePath);

    $average = ecotradeWatermarkAverageRgb($sourcePath);

    expect($average['red'])->toBeGreaterThan($average['green'] + 50)
        ->and($average['red'])->toBeGreaterThan($average['blue'] + 50);

    @unlink($sourcePath);
});
