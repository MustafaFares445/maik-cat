<?php

namespace App\Services;

use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ItemSiblingMediaCopier
{
    public function copyFirstImageTo(Item $destination): ?Media
    {
        return $this->copyFirstImageToInternal($destination, false);
    }

    private function copyFirstImageToInternal(Item $destination, bool $afterCommit): ?Media
    {
        if (DB::transactionLevel() > 0 && ! $afterCommit) {
            $itemId = (string) $destination->getKey();

            DB::afterCommit(function () use ($itemId): void {
                $committedItem = Item::query()->find($itemId);

                if ($committedItem instanceof Item) {
                    $this->copyFirstImageToInternal($committedItem, true);
                }
            });

            return null;
        }

        if ($destination->hasMedia('images')) {
            return $destination->getFirstMedia('images');
        }

        $normalizedSerial = Item::normalizeSerialValue($destination->normalized_serial ?: $destination->serial_code);

        if ($normalizedSerial === '' || blank($destination->car_group_id)) {
            return null;
        }

        $source = $this->findSiblingWithImage($destination, $normalizedSerial);

        $sourceMedia = $source?->getFirstMedia('images');

        if (! $sourceMedia instanceof Media) {
            return null;
        }

        return $sourceMedia->copy($destination, 'images', 'public');
    }

    private function findSiblingWithImage(Item $destination, string $normalizedSerial): ?Item
    {
        $baseQuery = Item::query()
            ->with('media')
            ->where($destination->getKeyName(), '!=', $destination->getKey())
            ->where('car_group_id', $destination->car_group_id)
            ->whereHas('media', static function (Builder $query): void {
                $query->where('collection_name', 'images');
            });

        $indexedSource = (clone $baseQuery)
            ->where('normalized_serial', $normalizedSerial)
            ->oldest('created_at')
            ->first();

        if ($indexedSource instanceof Item) {
            return $indexedSource;
        }

        return $baseQuery
            ->whereRaw($this->normalizedSerialSql().' = ?', [$normalizedSerial])
            ->oldest('created_at')
            ->first();
    }

    public function copyFirstImageToSiblings(Item $source): int
    {
        return $this->copyFirstImageToSiblingsInternal($source, false);
    }

    private function copyFirstImageToSiblingsInternal(Item $source, bool $afterCommit): int
    {
        if (DB::transactionLevel() > 0 && ! $afterCommit) {
            $itemId = (string) $source->getKey();

            DB::afterCommit(function () use ($itemId): void {
                $committedItem = Item::query()->find($itemId);

                if ($committedItem instanceof Item) {
                    $this->copyFirstImageToSiblingsInternal($committedItem, true);
                }
            });

            return 0;
        }

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
