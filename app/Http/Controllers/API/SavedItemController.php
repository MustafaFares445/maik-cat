<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\StoreSavedItemRequest;
use App\Http\Resources\API\ItemResource;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $items = $user->savedItems()
            ->apiVisible()
            ->with(['carGroup', 'extraCodes', 'media'])
            ->withExists([
                'savedByUsers as saved_item' => fn(Builder $builder) => $builder->where('users.id', $user->getKey()),
            ])
            ->latest('saved_items.created_at')
            ->get();

        return response()->json([
            'data' => ItemResource::collection($items)->resolve(),
        ]);
    }

    public function store(StoreSavedItemRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $itemId = $request->validated('item_id');

        $user->savedItems()->syncWithoutDetaching([$itemId]);

        return response()->json([
            'message' => 'Item saved successfully.',
        ], 201);
    }

    public function destroy(Request $request, Item $item): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $user->savedItems()->detach($item->getKey());

        return response()->json([
            'message' => 'Item removed from saved list.',
        ]);
    }
}
