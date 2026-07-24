<?php

namespace App\Services;

use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ItemSiblingMediaCopier
{
    public function copyFirstImageTo(Item $destination): ?Media
    {
        if ($destination->hasMedia('images')) {
            return $destination->getFirstMedia('images');
        }

        $normalizedSerial = Item::normalizeSerialValue($destination->normalized_serial ?: $destination->serial_code);

        if ($normalizedSerial === '' || blank($destination->car_group_id)) {
            return null;
        }

        $source = Item::query()
            ->with('media')
            ->where($destination->getKeyName(), '!=', $destination->getKey())
            ->where('car_group_id', $destination->car_group_id)
            ->where(function (Builder $query) use ($normalizedSerial): void {
                $query->where('normalized_serial', $normalizedSerial)
                    ->orWhereRaw($this->normalizedSerialSql().' = ?', [$normalizedSerial]);
            })
            ->whereHas('media', static function (Builder $query): void {
                $query->where('collection_name', 'images');
            })
            ->oldest('created_at')
            ->first();

        $sourceMedia = $source?->getFirstMedia('images');

        if (! $sourceMedia instanceof Media) {
            return null;
        }

        return $sourceMedia->copy($destination, 'images', 'public');
    }

    public function copyFirstImageToSiblings(Item $source): int
    {
        $sourceMedia = $source->getFirstMedia('images');
        $normalizedSerial = Item::normalizeSerialValue($source->normalized_serial ?: $source->serial_code);

        if (! $sourceMedia instanceof Media || $normalizedSerial === '' || blank($source->car_group_id)) {
            return 0;
        }

        $copied = 0;

        Item::query()
            ->with('media')
            ->where($source->getKeyName(), '!=', $source->getKey())
            ->where('car_group_id', $source->car_group_id)
            ->where(function (Builder $query) use ($normalizedSerial): void {
                $query->where('normalized_serial', $normalizedSerial)
                    ->orWhereRaw($this->normalizedSerialSql().' = ?', [$normalizedSerial]);
            })
            ->whereDoesntHave('media', static function (Builder $query): void {
                $query->where('collection_name', 'images');
            })
            ->get()
            ->each(function (Item $sibling) use ($sourceMedia, &$copied): void {
                $sourceMedia->copy($sibling, 'images', 'public');
                $copied++;
            });

        return $copied;
    }

    private function normalizedSerialSql(): string
    {
        return "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(serial_code, ''), ' ', ''), '-', ''), '/', ''), '.', ''))";
    }
}
