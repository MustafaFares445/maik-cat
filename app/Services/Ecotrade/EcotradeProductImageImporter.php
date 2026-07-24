<?php

namespace App\Services\Ecotrade;

use App\Data\EcotradeProductImageCandidate;
use App\Services\ItemSiblingMediaCopier;
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
        private readonly ItemSiblingMediaCopier $mediaCopier,
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
                $this->watermarkApplier->apply($path);
            }

            if ($replaceExisting) {
                $candidate->item->clearMediaCollection('images');
            }

            $media = $candidate->item
                ->addMedia($path)
                ->usingName($this->mediaName($candidate))
                ->usingFileName($this->fileName($candidate, $extension, $watermarkMode))
                ->withCustomProperties([
                    'source' => 'ecotrade',
                    'source_url' => $candidate->sourceImageUrl,
                    'source_hash' => $candidate->product->sourceHash,
                    'gemini_model' => config('services.gemini.image_model', 'gemini-2.5-flash-image'),
                    'gemini_result' => 'edited',
                    'gemini_processed_at' => now()->toISOString(),
                    'gemini_prompt_version' => 'ecotrade-product-clean-v3',
                    'watermark_mode' => $watermarkMode,
                    'watermark_text' => $watermarkMode === 'ai' ? $watermarkText : null,
                    'watermark_asset' => $watermarkMode === 'spatie' ? 'resources/images/ecotrade/maikcat-transparent-v2.png' : null,
                    'maikcat_watermark' => $watermarkMode !== 'none',
                ])
                ->toMediaCollection('images');

            $this->mediaCopier->copyFirstImageToSiblings($candidate->item->fresh('media'));

            return $media;
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** @return array{bytes:string,mime_type:string} */
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

        $mimeType = $this->detectMimeType($bytes, (string) $response->header('Content-Type'));

        if (! str_starts_with($mimeType, 'image/')) {
            throw new RuntimeException('Downloaded source is not a supported image.');
        }

        return [
            'bytes' => $bytes,
            'mime_type' => $mimeType,
        ];
    }

    private function buildPrompt(string $watermarkMode, string $watermarkText): string
    {
        $watermarkInstruction = match ($watermarkMode) {
            'ai' => "\nRemove the original supplier watermark, logo, signature, label, and copyright notice, and do not add any source attribution other than the requested mark. Add multiple visible repeated watermarks with the exact text \"{$watermarkText}\", semi-transparent and evenly spaced across the image.",
            default => "\nRemove the original supplier watermark, logo, signature, label, copyright notice, and any source attribution. Do not add any new logos, branding, text, or watermarks; a separate watermark is applied afterwards.",
        };

        return trim(<<<PROMPT
Prepare the attached product photo for a clean e-commerce catalog listing.

Goal:
Improve the image quality and remove the original supplier watermark while keeping the physical product exactly as it appears in the source photo.

Image context:
The image shows an isolated rusty automotive exhaust/catalytic converter component on a plain white or light background. The part has a silver/gray cylindrical body, rusty brown corrosion, flanges, bolts/studs, seams, a small threaded port on top, and a curved outlet section.

Enhancement requirements:
Preserve crisp focus, edge definition, and fine surface texture.
Improve sharpness, clarity, lighting balance, and overall image quality.
Reduce noise and compression artifacts without smoothing away real surface detail.

Product fidelity requirements (critical):
Preserve the exact object shape, silhouette, angle, perspective, and proportions.
Preserve the original rusty metal texture and natural corrosion patterns.
Keep every real bolt, stud, hole, weld, seam, threaded port, scratch, and dent exactly as shown.
Do not change the product type or redesign the component.
Do not make the object look new, polished, repaired, or cleaner than the original.
Do not invent, reconstruct, or hallucinate any detail that is not present in the source photo.
Do not crop important parts of the object.
Preserve the plain white/light background.

Watermark requirements:{$watermarkInstruction}

Quality requirements:
The final image must show the same physical product as the source photo, only cleaner and sharper.
No obvious blur patches, smears, duplicated texture, AI artifacts, or fake-looking reconstruction should be introduced.
Keep the same composition and aspect ratio as the original image.

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

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = $finfo !== false ? finfo_buffer($finfo, $bytes) : null;

        if ($finfo !== false) {
            finfo_close($finfo);
        }

        return is_string($detected) && str_starts_with($detected, 'image/') ? $detected : 'application/octet-stream';
    }

    private function extensionForMimeType(string $mimeType): string
    {
        return match (strtolower($mimeType)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/png' => 'png',
            default => throw new RuntimeException('Unsupported image MIME type: '.$mimeType),
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
        $name = Str::slug(trim((string) $candidate->item->serial_code)) ?: Str::slug($this->mediaName($candidate));
        $name = $name !== '' ? $name : 'ecotrade-product';
        $suffix = $watermarkMode === 'none' ? '' : '-maikcat';

        return $name.$suffix.'.'.$extension;
    }

    private function mediaName(EcotradeProductImageCandidate $candidate): string
    {
        $serial = trim((string) $candidate->item->serial_code);

        if ($serial !== '') {
            return $serial;
        }

        $productName = trim((string) $candidate->product->productName);

        return $productName !== '' ? $productName : 'Ecotrade product image';
    }
}
