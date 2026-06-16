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
            $watermark = $this->buildWatermarkLayer(imagesx($image), imagesy($image));

            imagealphablending($image, true);
            imagesavealpha($image, true);

            if (! imagecopy($image, $watermark, 0, 0, 0, 0, imagesx($image), imagesy($image))) {
                throw new RuntimeException('Unable to apply Maikcat watermark to image: '.$imagePath);
            }

            $this->saveImage($image, $imagePath, $mimeType);
        } finally {
            imagedestroy($image);

            if ($watermark instanceof \GdImage) {
                imagedestroy($watermark);
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

    private function watermarkImagePath(): string
    {
        $path = resource_path('images/ecotrade/maikcat-watermark.png');

        if (is_file($path)) {
            return $path;
        }

        throw new RuntimeException('Local Maikcat watermark image is missing: '.$path);
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

    private function buildWatermarkLayer(int $width, int $height): \GdImage
    {
        $source = $this->loadWatermarkAsset();
        $canvas = null;

        try {
            $canvas = $this->transparentCanvas($width, $height);
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);

            for ($top = 0; $top < $height; $top += $sourceHeight) {
                for ($left = 0; $left < $width; $left += $sourceWidth) {
                    if (! imagecopy($canvas, $source, $left, $top, 0, 0, $sourceWidth, $sourceHeight)) {
                        throw new RuntimeException('Unable to place local Maikcat watermark image.');
                    }
                }
            }

            return $canvas;
        } catch (\Throwable $exception) {
            if ($canvas instanceof \GdImage) {
                imagedestroy($canvas);
            }

            throw $exception;
        } finally {
            imagedestroy($source);
        }
    }

    private function loadWatermarkAsset(): \GdImage
    {
        $sourcePath = $this->watermarkImagePath();
        $source = imagecreatefrompng($sourcePath);

        if ($source === false) {
            throw new RuntimeException('Unable to load local Maikcat watermark image: '.$sourcePath);
        }

        imagepalettetotruecolor($source);
        imagealphablending($source, false);
        imagesavealpha($source, true);
        $this->removeCheckerboardBackground($source);

        return $source;
    }

    private function transparentCanvas(int $width, int $height): \GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);

        if ($canvas === false) {
            throw new RuntimeException('Unable to create Maikcat watermark canvas.');
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagefill($canvas, 0, 0, $transparent);

        return $canvas;
    }

    private function removeCheckerboardBackground(\GdImage $image): void
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $visibleInk = imagecolorallocatealpha($image, 34, 34, 34, 92);
        $transparent = imagecolorallocatealpha($image, 255, 255, 255, 127);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($image, $x, $y);
                $red = ($color >> 16) & 0xFF;
                $green = ($color >> 8) & 0xFF;
                $blue = $color & 0xFF;

                if ($red > 235 && $green > 235 && $blue > 235) {
                    imagesetpixel($image, $x, $y, $visibleInk);

                    continue;
                }

                imagesetpixel($image, $x, $y, $transparent);
            }
        }
    }
}
