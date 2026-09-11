<?php

declare(strict_types=1);

namespace App\Services\Media;

use GdImage;
use RuntimeException;

final class PhpImageSimilarity
{
    private const int SAMPLE_SIZE = 24;

    /** @return list<int> */
    public function fingerprintFromPath(string $path): array
    {
        $bytes = file_get_contents($path);

        if ($bytes === false) {
            throw new RuntimeException('Unable to read the current media file.');
        }

        return $this->fingerprintFromBytes($bytes);
    }

    /** @return list<int> */
    public function fingerprintFromBytes(string $bytes): array
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The PHP GD extension is not available.');
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw new RuntimeException('The image bytes cannot be decoded by PHP GD.');
        }

        try {
            return $this->grayscaleSamples($image);
        } finally {
            imagedestroy($image);
        }
    }

    /** @param list<int> $current @param list<int> $reference */
    public function compare(array $current, array $reference): float
    {
        if (count($current) !== count($reference) || $current === []) {
            throw new RuntimeException('Image fingerprints have incompatible dimensions.');
        }

        $luminanceDifference = 0;
        $gradientMatches = 0;
        $gradientCount = 0;

        foreach ($current as $index => $luminance) {
            $luminanceDifference += abs($luminance - $reference[$index]);
        }

        for ($row = 0; $row < self::SAMPLE_SIZE; $row++) {
            for ($column = 0; $column < self::SAMPLE_SIZE - 1; $column++) {
                $leftIndex = ($row * self::SAMPLE_SIZE) + $column;
                $rightIndex = $leftIndex + 1;
                $gradientMatches += ($current[$leftIndex] < $current[$rightIndex])
                    === ($reference[$leftIndex] < $reference[$rightIndex]) ? 1 : 0;
                $gradientCount++;
            }
        }

        $luminanceSimilarity = 1 - ($luminanceDifference / (count($current) * 255));
        $gradientSimilarity = $gradientMatches / $gradientCount;

        return round(($luminanceSimilarity * 0.35) + ($gradientSimilarity * 0.65), 4);
    }

    /** @return list<int> */
    private function grayscaleSamples(GdImage $source): array
    {
        $sample = imagecreatetruecolor(self::SAMPLE_SIZE, self::SAMPLE_SIZE);
        $white = imagecolorallocate($sample, 255, 255, 255);
        imagefill($sample, 0, 0, $white);
        imagecopyresampled(
            $sample,
            $source,
            0,
            0,
            0,
            0,
            self::SAMPLE_SIZE,
            self::SAMPLE_SIZE,
            imagesx($source),
            imagesy($source),
        );

        try {
            return $this->readLuminanceSamples($sample);
        } finally {
            imagedestroy($sample);
        }
    }

    /** @return list<int> */
    private function readLuminanceSamples(GdImage $sample): array
    {
        $luminance = [];

        for ($row = 0; $row < self::SAMPLE_SIZE; $row++) {
            for ($column = 0; $column < self::SAMPLE_SIZE; $column++) {
                $rgb = imagecolorat($sample, $column, $row);
                $red = ($rgb >> 16) & 0xFF;
                $green = ($rgb >> 8) & 0xFF;
                $blue = $rgb & 0xFF;
                $luminance[] = (int) round(($red * 0.299) + ($green * 0.587) + ($blue * 0.114));
            }
        }

        return $luminance;
    }
}
