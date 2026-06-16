<?php

namespace App\Services\Ecotrade;

use App\Data\EcotradeProductImageCandidate;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
        $geminiResult = 'edited';

        try {
            $edited = $this->gemini->edit($source['bytes'], $source['mime_type'], $prompt);
        } catch (EcotradeGeminiImageUnavailableException) {
            $edited = $source;
            $geminiResult = 'source_fallback';
        }

        $extension = $this->extensionForMimeType($edited['mime_type']);
        $path = $this->writeTempFile($edited['bytes'], $extension);

        try {
            if ($watermarkMode === 'spatie') {
                $this->watermarkApplier->apply($path);
            }

            if ($replaceExisting) {
                $candidate->item->clearMediaCollection('images');
            }

            return $candidate->item
                ->addMedia($path)
                ->usingName($this->mediaName($candidate))
                ->usingFileName($this->fileName($candidate, $extension, $watermarkMode))
                ->withCustomProperties([
                    'source' => 'ecotrade',
                    'source_url' => $candidate->sourceImageUrl,
                    'source_hash' => $candidate->product->sourceHash,
                    'gemini_model' => config('services.gemini.image_model', 'gemini-2.5-flash-image'),
                    'gemini_result' => $geminiResult,
                    'gemini_processed_at' => now()->toISOString(),
                    'gemini_prompt_version' => 'ecotrade-product-preserve-v2',
                    'watermark_mode' => $watermarkMode,
                    'watermark_text' => $watermarkMode === 'ai' ? $watermarkText : null,
                    'watermark_asset' => $watermarkMode === 'spatie' ? 'resources/images/ecotrade/maikcat-watermark.png' : null,
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
            'ai' => "\nAdd multiple visible repeated watermarks with the exact text \"{$watermarkText}\". Keep them semi-transparent and do not cover, hide, remove, blur, or replace any existing watermark, logo, copyright notice, signature, label, or attribution mark.",
            default => "\nDo not add any new logos, branding, text, watermarks, labels, or extra objects in the Gemini edit.",
        };

        return trim(<<<PROMPT
Prepare the attached product photo for catalog display while preserving the original image ownership and attribution marks.

Goal:
Create a realistic product image suitable for an e-commerce catalog without changing what the product is and without removing or obscuring source attribution.

Image context:
The image shows an isolated rusty automotive exhaust/catalytic converter component on a plain white or light background. The part has a silver/gray cylindrical body, rusty brown corrosion, flanges, bolts/studs, seams, a small threaded port on top, and a curved outlet section.

Editing requirements:
Preserve any existing copyright notices, logos, signatures, watermarks, labels, or attribution marks already present in the source image.
Do not remove, hide, blur, cover, replace, imitate, or alter any existing watermark, logo, copyright notice, signature, label, or attribution mark.
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
The final image should look like the original product photo with source attribution preserved.
No obvious blur patches, smears, duplicated texture, AI artifacts, or fake-looking reconstruction should be introduced.
Keep the same composition and preferably the same aspect ratio as the original image.

Output:
Return only the edited image.
PROMPT);
    }

    private function normalizeWatermarkMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return in_array($mode, ['spatie', 'ai', 'none'], true) ? $mode : 'none';
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

    private function fileName(EcotradeProductImageCandidate $candidate, string $extension, string $watermarkMode): string
    {
        $name = $this->mediaName($candidate);
        $name = Str::slug($name) ?: 'ecotrade-product';
        $suffix = $watermarkMode === 'none' ? '' : '-maikcat';

        return $name.$suffix.'.'.$extension;
    }

    private function mediaName(EcotradeProductImageCandidate $candidate): string
    {
        $productName = trim((string) $candidate->product->productName);

        if ($productName !== '') {
            return $productName;
        }

        $itemName = trim((string) $candidate->item->model);

        if ($itemName !== '') {
            return $itemName;
        }

        $serial = trim((string) $candidate->item->serial_code);

        return $serial !== '' ? $serial : 'Ecotrade product image';
    }
}
