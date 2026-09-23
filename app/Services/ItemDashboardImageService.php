<?php

namespace App\Services;

use App\Models\Item;
use App\Services\Ecotrade\EcotradeMaikcatWatermarkApplier;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ItemDashboardImageService
{
    public function __construct(
        private readonly EcotradeMaikcatWatermarkApplier $watermarkApplier,
    ) {}

    public function replaceFromPublicUpload(Item $item, ?string $relativePath): ?Media
    {
        if (! is_string($relativePath) || trim($relativePath) === '') {
            return $item->getFirstMedia('images');
        }

        $relativePath = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        $currentMedia = $item->getFirstMedia('images');

        if ($currentMedia instanceof Media) {
            $currentPath = ltrim(str_replace('\\', '/', $currentMedia->getPathRelativeToRoot()), '/');

            if ($currentPath === $relativePath) {
                return $currentMedia;
            }
        }

        $absolutePath = Storage::disk('public')->path($relativePath);

        if (! is_file($absolutePath)) {
            throw new RuntimeException('The uploaded item image could not be found.');
        }

        $this->watermarkApplier->apply($absolutePath);

        $item->clearMediaCollection('images');

        return $item
            ->addMedia($absolutePath)
            ->withCustomProperties([
                'source' => 'dashboard',
                'watermark_mode' => 'spatie',
                'watermark_asset' => 'resources/images/ecotrade/maikcat-transparent-v2.png',
                'maikcat_watermark' => true,
                'dashboard_uploaded_at' => now()->toISOString(),
            ])
            ->toMediaCollection('images');
    }
}
