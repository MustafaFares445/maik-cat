<?php

namespace App\Services\Ecotrade;

use RuntimeException;
use Spatie\Image\Image;

class EcotradeMaikcatWatermarkApplier
{
    public function apply(string $imagePath, string $text = 'maikcat'): void
    {
        $text = trim($text) !== '' ? trim($text) : 'maikcat';
        $fontPath = $this->fontPath();
        $image = Image::load($imagePath);
        $width = $image->getWidth();
        $height = $image->getHeight();

        $fontSize = max(12, min(44, (int) floor($width / 13)));
        $stepX = max(130, $fontSize * max(strlen($text), 6) * 3);
        $stepY = max(90, $fontSize * 4);
        $offset = (int) floor($stepX / 3);

        for ($y = (int) floor($stepY / 2); $y < ($height + $stepY); $y += $stepY) {
            for ($x = -$offset; $x < ($width + $stepX); $x += $stepX) {
                $image->text(
                    text: $text,
                    fontSize: $fontSize,
                    color: 'rgba(65, 65, 65, 0.22)',
                    x: $x,
                    y: $y,
                    angle: -25,
                    fontPath: $fontPath,
                );
            }
        }

        $image->save($imagePath);
    }

    private function fontPath(): string
    {
        $candidates = [
            base_path('resources/fonts/arial.ttf'),
            'C:\\Windows\\Fonts\\arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('No TrueType font is available for local Maikcat watermarking.');
    }
}
