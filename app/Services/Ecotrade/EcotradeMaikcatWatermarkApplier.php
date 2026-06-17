<?php

namespace App\Services\Ecotrade;

use RuntimeException;

class EcotradeMaikcatWatermarkApplier
{
    public function apply(string $imagePath): void
    {
        [$image, $mimeType] = $this->loadImage($imagePath);
        $watermark = null;

        try {
            $this->sharpenImage($image);

            imagealphablending($image, true);
            imagesavealpha($image, true);

            $watermark = $this->loadWatermarkAsset();
            $this->stampWatermark($image, $watermark);

            $this->saveImage($image, $imagePath, $mimeType);
        } finally {
            imagedestroy($image);

            if ($watermark instanceof \GdImage) {
                imagedestroy($watermark);
            }
        }
    }

    private function stampWatermark(\GdImage $image, \GdImage $watermark): void
    {
        $imageWidth = imagesx($image);
        $imageHeight = imagesy($image);
        $tileWidth = imagesx($watermark);
        $tileHeight = imagesy($watermark);

        for ($top = 0; $top < $imageHeight; $top += $tileHeight) {
            for ($left = 0; $left < $imageWidth; $left += $tileWidth) {
                imagecopy($image, $watermark, $left, $top, 0, 0, $tileWidth, $tileHeight);
            }
        }
    }

    private function sharpenImage(\GdImage $image): void
    {
        $kernel = [
            [0, -1, 0],
            [-1, 5, -1],
            [0, -1, 0],
        ];

        if (! imageconvolution($image, $kernel, 1, 0)) {
            throw new RuntimeException('Unable to sharpen Maikcat watermarked image.');
        }
    }

    /**
     * @return array{0: \GdImage, 1: string}
     */
    private function loadImage(string $imagePath): array
    {
        $imageInfo = getimagesize($imagePath);
        $mimeType = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';

        $image = match ($mimeType) {
            'image/jpeg' => imagecreatefromjpeg($imagePath),
            'image/png' => imagecreatefrompng($imagePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($imagePath) : false,
            'image/avif' => function_exists('imagecreatefromavif') ? imagecreatefromavif($imagePath) : false,
            default => false,
        };

        if ($image === false) {
            throw new RuntimeException('Unsupported image type for Maikcat watermark: '.$mimeType);
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return [$image, $mimeType];
    }

    private function saveImage(\GdImage $image, string $imagePath, string $mimeType): void
    {
        $saved = match ($mimeType) {
            'image/jpeg' => imagejpeg($image, $imagePath, 92),
            'image/png' => imagepng($image, $imagePath),
            'image/webp' => function_exists('imagewebp') ? imagewebp($image, $imagePath, 90) : false,
            'image/avif' => function_exists('imageavif') ? imageavif($image, $imagePath, 80) : false,
            default => false,
        };

        if ($saved === false) {
            throw new RuntimeException('Unable to save Maikcat watermarked image: '.$imagePath);
        }
    }

    private function loadWatermarkAsset(): \GdImage
    {
        $path = resource_path('images/ecotrade/maikcat-transparent-v2.png');

        if (! is_file($path)) {
            throw new RuntimeException('Maikcat watermark image is missing: '.$path);
        }

        $watermark = imagecreatefrompng($path);

        if ($watermark === false) {
            throw new RuntimeException('Unable to load Maikcat watermark image: '.$path);
        }

        // Preserve the asset's existing alpha channel so it composites at its designed opacity.
        imagepalettetotruecolor($watermark);
        imagealphablending($watermark, false);
        imagesavealpha($watermark, true);

        return $watermark;
    }
}
