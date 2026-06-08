<?php

namespace App\Services\Ecotrade;

use App\Data\EcotradeProductImageCandidate;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class EcotradeProductImageImporter
{
    public function __construct(
        private readonly EcotradeGeminiImageEditor $gemini,
        private readonly EcotradeMaikcatWatermarkApplier $watermarkApplier,
    ) {}

    public function import(EcotradeProductImageCandidate $candidate, string $watermarkMode, string $watermarkText, bool $replaceExisting = false): Media
    {
        $watermarkMode = $this->normalizeWatermarkMode($watermarkMode);
        $source = $this->downloadSourceImage($candidate->sourceImageUrl);
        $prompt = $this->buildPrompt($watermarkMode, $watermarkText);
        $edited = $this->gemini->edit($source['bytes'], $source['mime_type'], $prompt);
        $extension = $this->extensionForMimeType($edited['mime_type']);
        $path = $this->writeTempFile($edited['bytes'], $extension);

        try {
            if ($watermarkMode === 'spatie') {
                $this->watermarkApplier->apply($path, $watermarkText);
            }

            if ($replaceExisting) {
                $candidate->item->clearMediaCollection('images');
            }

            return $candidate->item
                ->addMedia($path)
                ->usingName((string) ($candidate->item->serial_code ?: $candidate->item->model ?: 'Ecotrade product image'))
                ->usingFileName($this->fileName($candidate, $extension))
                ->withCustomProperties([
                    'source' => 'ecotrade',
                    'source_url' => $candidate->sourceImageUrl,
                    'source_hash' => $candidate->product->sourceHash,
                    'gemini_model' => config('services.gemini.image_model', 'gemini-2.5-flash-image'),
                    'gemini_processed_at' => now()->toISOString(),
                    'gemini_prompt_version' => 'ecotrade-product-watermark-v1',
                    'watermark_mode' => $watermarkMode,
                    'watermark_text' => $watermarkMode === 'none' ? null : $watermarkText,
                    'maikcat_watermark' => $watermarkMode !== 'none',
                ])
                ->toMediaCollection('images');
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @return array{bytes: string, mime_type: string}
     */
    private function downloadSourceImage(string $url): array
    {
        $timeout = max(1, (int) config('services.gemini.image_download_timeout', 30));

        try {
            $response = Http::timeout($timeout)->get($url)->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException('Unable to download source image: '.$exception->getMessage(), previous: $exception);
        }

        $bytes = $response->body();

        if ($bytes === '') {
            throw new RuntimeException('Downloaded source image is empty.');
        }

        return [
            'bytes' => $bytes,
            'mime_type' => $this->detectMimeType($bytes, (string) $response->header('Content-Type')),
        ];
    }

    private function buildPrompt(string $watermarkMode, string $watermarkText): string
    {
        $watermarkInstruction = match ($watermarkMode) {
            'ai' => "\nAfter removing the old watermark, add multiple visible repeated watermarks with the exact text \"{$watermarkText}\" similar to the original Ecotrade/Ecocate watermark pattern. Do not add any other text.",
            default => "\nDo not add any new logos, branding, text, watermarks, labels, or extra objects.",
        };

        return trim(<<<PROMPT
Edit the attached product image to remove all visible watermark artifacts.

Goal:
Create a clean product image with no visible old watermark text, symbols, repeated logo marks, haze, ghosting, or semi-transparent overlay.

Image context:
The image shows an isolated rusty automotive exhaust/catalytic converter component on a plain white or light background. The part has a silver/gray cylindrical body, rusty brown corrosion, flanges, bolts/studs, seams, a small threaded port on top, and a curved outlet section.

Editing requirements:
Remove the watermark completely from the product surface and background.
Reconstruct the hidden areas naturally using surrounding texture, color, rust, shadows, and metal details.
Preserve the exact object shape, silhouette, angle, perspective, and proportions.
Preserve the original rusty metal texture and natural corrosion patterns.
Preserve the original lighting, shadows, contrast, and product-photo style.
Preserve the plain white/light background.
Do not change the product type or redesign the component.
Do not make the object look new, polished, or cleaner than the original.
Do not remove real rust, scratches, dirt, seams, bolts, holes, or product details.
Do not crop important parts of the object.
Keep the result realistic, natural, and suitable for an e-commerce product image.{$watermarkInstruction}

Quality requirements:
The final image should look like the original product photo, but without the old watermark.
The cleaned areas should blend smoothly with the surrounding pixels.
No obvious blur patches, smears, duplicated texture, AI artifacts, or fake-looking reconstruction should remain.
Keep the same composition and preferably the same aspect ratio as the original image.

Output:
Return only the edited image.
PROMPT);
    }

    private function normalizeWatermarkMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return in_array($mode, ['spatie', 'ai', 'none'], true) ? $mode : 'spatie';
    }

    private function detectMimeType(string $bytes, string $header): string
    {
        $header = strtolower(trim(strtok($header, ';') ?: ''));

        if (str_starts_with($header, 'image/')) {
            return $header;
        }

        $detected = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $bytes) ?: null;

        return is_string($detected) && str_starts_with($detected, 'image/') ? $detected : 'image/png';
    }

    private function extensionForMimeType(string $mimeType): string
    {
        return match (strtolower($mimeType)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default => 'png',
        };
    }

    private function writeTempFile(string $bytes, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ecotrade_gemini_');

        if ($path === false) {
            throw new RuntimeException('Unable to allocate temporary image file.');
        }

        $target = $path.'.'.$extension;
        @unlink($path);

        file_put_contents($target, $bytes);

        return $target;
    }

    private function fileName(EcotradeProductImageCandidate $candidate, string $extension): string
    {
        $serial = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $candidate->item->serial_code) ?: 'ecotrade-product';
        $serial = trim(strtolower($serial), '-');

        return $serial.'-gemini-maikcat.'.$extension;
    }
}
