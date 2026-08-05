<?php

declare(strict_types=1);

namespace App\Services\Ecotrade;

use App\Data\EcotradeProductImageCandidate;
use App\Services\ItemSiblingMediaCopier;
use Illuminate\Support\Str;

final class EcotradeDirectProductImageImporter
{
    public function __construct(
        private readonly EcotradeSourceImageDownloader $downloader,
        private readonly ItemSiblingMediaCopier $mediaCopier,
    ) {}

    /** Return the number of database items linked, including copied siblings. */
    public function import(EcotradeProductImageCandidate $candidate, bool $replaceExisting = false): int
    {
        $source = $this->downloader->download($candidate->sourceImageUrl);
        $extension = $this->downloader->extensionForMimeType($source['mime_type']);
        $path = $this->writeTempFile($source['bytes'], $extension);

        try {
            if ($replaceExisting) {
                $candidate->item->clearMediaCollection('images');
            }

            $media = $candidate->item
                ->addMedia($path)
                ->usingName($this->mediaName($candidate))
                ->usingFileName($this->fileName($candidate, $extension))
                ->withCustomProperties([
                    'source' => 'ecotrade',
                    'source_url' => $candidate->sourceImageUrl,
                    'source_hash' => $candidate->product->sourceHash,
                    'import_method' => 'direct',
                    'gemini_result' => null,
                    'maikcat_watermark' => false,
                ])
                ->toMediaCollection('images');

            return 1 + $this->mediaCopier->copyFirstImageToSiblings($candidate->item->fresh('media'));
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function writeTempFile(string $bytes, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ecotrade_direct_');

        if ($path === false) {
            throw new \RuntimeException('Unable to allocate temporary Ecotrade image file.');
        }

        $target = $path.'.'.$extension;
        @unlink($path);
        file_put_contents($target, $bytes);

        return $target;
    }

    private function fileName(EcotradeProductImageCandidate $candidate, string $extension): string
    {
        $name = Str::slug(trim((string) $candidate->item->serial_code));
        $name = $name !== '' ? $name : Str::slug(trim((string) $candidate->product->productName));
        $name = $name !== '' ? $name : 'ecotrade-product';

        return $name.'-ecotrade-direct.'.$extension;
    }

    private function mediaName(EcotradeProductImageCandidate $candidate): string
    {
        $serial = trim((string) $candidate->item->serial_code);

        return $serial !== '' ? $serial : ($candidate->product->productName ?: 'Ecotrade product image');
    }
}
