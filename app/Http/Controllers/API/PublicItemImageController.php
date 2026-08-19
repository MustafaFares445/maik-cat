<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

        $items = Item::query()
            ->where(function (Builder $query) use ($normalizedCode): void {
                $query->where('normalized_serial', $normalizedCode)
                    ->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(serial_code), ' ', ''), '-', ''), '.', ''), '/', '') = ?",
                        [$normalizedCode],
                    )
                    ->orWhereHas('extraCodes', static fn (Builder $extraCodes): Builder => $extraCodes->whereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(code), ' ', ''), '-', ''), '.', ''), '/', '') = ?",
                        [$normalizedCode],
                    ));
            })
            ->get();

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
        $fileName = Str::uuid().'.'.$extension;

        $updatedItems = [];

        foreach ($items as $item) {
            $item->clearMediaCollection('images');

            $item->addMediaFromString($imageContents)
                ->usingFileName($fileName)
                ->toMediaCollection('images');

            // The Item model defines non-queued conversions, so adding the media
            // regenerates thumb, card, and detail conversions before this response.
            $item->unsetRelation('media');
            $item->load('media');

            $updatedItems[] = [
                'id' => $item->getKey(),
                'serial_code' => $item->serial_code,
                'image_url' => $item->image_url,
                'image_thumb_url' => $item->image_thumb_url,
                'image_detail_url' => $item->image_detail_url,
            ];
        }

        return response()->json([
            'message' => 'Item images updated successfully.',
            'code' => $code,
            'normalized_code' => $normalizedCode,
            'updated_count' => count($updatedItems),
            'items' => $updatedItems,
        ]);
    }
}
