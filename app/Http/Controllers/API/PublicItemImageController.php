<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class PublicItemImageController extends Controller
{
    public function updateByCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,avif', 'max:20480'],
        ]);

        $code = trim((string) $validated['code']);
        $normalizedCode = Item::normalizeSerialValue($code);

        if ($normalizedCode === '') {
            return response()->json([
                'message' => 'The code field must contain a valid code.',
                'errors' => [
                    'code' => ['The code field must contain a valid code.'],
                ],
            ], 422);
        }

        $items = $this->matchingItemsQuery([$normalizedCode])->get();

        if ($items->isEmpty()) {
            return response()->json([
                'message' => 'No items were found for the provided code.',
                'code' => $code,
                'normalized_code' => $normalizedCode,
                'updated_count' => 0,
            ], 404);
        }

        $uploadedImage = $request->file('image');
        $imagePath = $uploadedImage?->getRealPath();
        $imageContents = is_string($imagePath) ? file_get_contents($imagePath) : false;

        if ($imageContents === false) {
            return response()->json([
                'message' => 'The uploaded image could not be read.',
            ], 422);
        }

        $extension = strtolower((string) ($uploadedImage->guessExtension() ?: $uploadedImage->getClientOriginalExtension() ?: 'jpg'));
        $updatedItems = [];

        foreach ($items as $item) {
            $updatedItems[] = $this->replaceItemImage($item, $imageContents, $extension);
        }

        return response()->json([
            'message' => 'Item images updated successfully.',
            'code' => $code,
            'normalized_code' => $normalizedCode,
            'updated_count' => count($updatedItems),
            'items' => $updatedItems,
        ]);
    }

    public function syncByMediaRange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'integer', 'min:1'],
            'to' => ['required', 'integer', 'gte:from'],
        ]);

        $from = (int) $validated['from'];
        $to = (int) $validated['to'];

        $sourceMedia = Media::query()
            ->where('model_type', Item::class)
            ->where('collection_name', 'images')
            ->whereBetween('id', [$from, $to])
            ->orderBy('id')
            ->get();

        if ($sourceMedia->isEmpty()) {
            return response()->json([
                'message' => 'No item images were found in the provided media range.',
                'from' => $from,
                'to' => $to,
                'source_count' => 0,
                'updated_count' => 0,
            ], 404);
        }

        // Snapshot every accepted/source item before changing anything. This keeps
        // all images whose media IDs are inside the accepted range untouched.
        $sourceItemIds = $sourceMedia
            ->pluck('model_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values();

        $sourceItems = Item::query()
            ->with('extraCodes')
            ->whereKey($sourceItemIds->all())
            ->get()
            ->keyBy(static fn (Item $item): string => (string) $item->getKey());

        $updatedTargetIds = [];
        $results = [];
        $processedSourceCount = 0;
        $skippedSourceCount = 0;
        $failedSourceCount = 0;

        foreach ($sourceMedia as $media) {
            $sourceItem = $sourceItems->get((string) $media->model_id);

            if (! $sourceItem instanceof Item) {
                $skippedSourceCount++;
                $results[] = [
                    'media_id' => $media->getKey(),
                    'source_item_id' => (string) $media->model_id,
                    'status' => 'skipped',
                    'reason' => 'Source item was not found.',
                    'updated_count' => 0,
                ];

                continue;
            }

            $normalizedCodes = $this->normalizedCodesForItem($sourceItem);

            if ($normalizedCodes === []) {
                $skippedSourceCount++;
                $results[] = [
                    'media_id' => $media->getKey(),
                    'source_item_id' => $sourceItem->getKey(),
                    'serial_code' => $sourceItem->serial_code,
                    'status' => 'skipped',
                    'reason' => 'Source item has no usable serial or extra code.',
                    'updated_count' => 0,
                ];

                continue;
            }

            $sourcePath = $media->getPath();
            $imageContents = is_file($sourcePath) ? file_get_contents($sourcePath) : false;

            if ($imageContents === false) {
                $failedSourceCount++;
                $results[] = [
                    'media_id' => $media->getKey(),
                    'source_item_id' => $sourceItem->getKey(),
                    'serial_code' => $sourceItem->serial_code,
                    'normalized_codes' => $normalizedCodes,
                    'status' => 'failed',
                    'reason' => 'Source image file could not be read.',
                    'updated_count' => 0,
                ];

                continue;
            }

            $extension = strtolower((string) (pathinfo($media->file_name, PATHINFO_EXTENSION) ?: 'jpg'));
            $targetsQuery = $this->matchingItemsQuery($normalizedCodes)
                ->whereNotIn('id', $sourceItemIds->all());

            if ($updatedTargetIds !== []) {
                // If one database item matches more than one accepted source code,
                // the first source media ID wins for this run and the item is not
                // rewritten repeatedly by later source images.
                $targetsQuery->whereNotIn('id', $updatedTargetIds);
            }

            $targets = $targetsQuery->orderBy('id')->get();
            $sourceUpdatedIds = [];
            $sourceErrors = [];

            foreach ($targets as $target) {
                try {
                    $this->replaceItemImage($target, $imageContents, $extension);
                    $targetId = (string) $target->getKey();
                    $updatedTargetIds[] = $targetId;
                    $sourceUpdatedIds[] = $targetId;
                } catch (Throwable $exception) {
                    report($exception);
                    $sourceErrors[] = [
                        'item_id' => $target->getKey(),
                        'message' => $exception->getMessage(),
                    ];
                }
            }

            $processedSourceCount++;
            $results[] = [
                'media_id' => $media->getKey(),
                'source_item_id' => $sourceItem->getKey(),
                'serial_code' => $sourceItem->serial_code,
                'normalized_codes' => $normalizedCodes,
                'status' => $sourceErrors === [] ? 'processed' : 'processed_with_errors',
                'updated_count' => count($sourceUpdatedIds),
                'updated_item_ids' => $sourceUpdatedIds,
                'errors' => $sourceErrors,
            ];
        }

        return response()->json([
            'message' => 'Accepted media range processed successfully.',
            'from' => $from,
            'to' => $to,
            'source_count' => $sourceMedia->count(),
            'processed_source_count' => $processedSourceCount,
            'skipped_source_count' => $skippedSourceCount,
            'failed_source_count' => $failedSourceCount,
            'updated_count' => count($updatedTargetIds),
            'results' => $results,
        ]);
    }

    private function matchingItemsQuery(array $normalizedCodes): Builder
    {
        return Item::query()->where(function (Builder $query) use ($normalizedCodes): void {
            foreach ($normalizedCodes as $normalizedCode) {
                $query->orWhere('normalized_serial', $normalizedCode)
                    ->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(serial_code), ' ', ''), '-', ''), '.', ''), '/', '') = ?",
                        [$normalizedCode],
                    )
                    ->orWhereHas('extraCodes', static fn (Builder $extraCodes): Builder => $extraCodes->whereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(code), ' ', ''), '-', ''), '.', ''), '/', '') = ?",
                        [$normalizedCode],
                    ));
            }
        });
    }

    private function normalizedCodesForItem(Item $item): array
    {
        $item->loadMissing('extraCodes');

        return collect([$item->serial_code])
            ->merge($item->extraCodes->pluck('code'))
            ->map(static fn (mixed $code): string => Item::normalizeSerialValue($code))
            ->filter(static fn (string $code): bool => $code !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function replaceItemImage(Item $item, string $imageContents, string $extension): array
    {
        // Item::registerMediaCollections() marks images as singleFile, so adding the
        // replacement automatically removes the previous image only after the new
        // media is accepted. Item conversions are non-queued and are regenerated here.
        $item->addMediaFromString($imageContents)
            ->usingFileName(Str::uuid().'.'.$extension)
            ->toMediaCollection('images');

        $item->unsetRelation('media');
        $item->load('media');

        return [
            'id' => $item->getKey(),
            'serial_code' => $item->serial_code,
            'image_url' => $item->image_url,
            'image_thumb_url' => $item->image_thumb_url,
            'image_detail_url' => $item->image_detail_url,
        ];
    }
}
