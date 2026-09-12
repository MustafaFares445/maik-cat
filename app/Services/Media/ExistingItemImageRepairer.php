<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\Item;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ExistingItemImageRepairer
{
    public function replaceWithExistingMedia(Item $destination, Media $source): Media
    {
        if ($source->model_type !== Item::class) {
            throw new RuntimeException('Source media does not belong to an item.');
        }

        if (! is_file($source->getPath())) {
            throw new RuntimeException('Source media file is missing: '.$source->getPath());
        }

        $newMedia = $source->copy($destination, 'images', 'public');
        $properties = is_array($newMedia->custom_properties) ? $newMedia->custom_properties : [];
        $newMedia->custom_properties = array_merge($properties, [
            'repair_method' => 'existing_project_media',
            'repair_source_item_id' => (string) $source->model_id,
            'repair_source_media_id' => $source->getKey(),
            'repair_applied_at' => now()->toISOString(),
        ]);
        $newMedia->save();

        return $newMedia;
    }
}
