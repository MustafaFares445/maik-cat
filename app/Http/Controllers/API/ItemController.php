<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\ItemFilterRequest;
use App\Http\Resources\API\ItemResource;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    public function index(ItemFilterRequest $request): JsonResponse
    {
        $userId = $request->user('sanctum')?->getKey();

        $itemsQuery = Item::getQuery($request);

        $this->applySavedItemFlag($itemsQuery, $userId);

        $items = $itemsQuery
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return response()->json([
            'data' => ItemResource::collection($items->getCollection())->resolve(),
            'links' => [
                'first' => $items->url(1),
                'last' => $items->url($items->lastPage()),
                'prev' => $items->previousPageUrl(),
                'next' => $items->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $items->currentPage(),
                'from' => $items->firstItem(),
                'last_page' => $items->lastPage(),
                'path' => $items->path(),
                'per_page' => $items->perPage(),
                'to' => $items->lastItem(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function show(Request $request, Item $item): JsonResponse
    {
        $userId = $request->user('sanctum')?->getKey();

        $item->load(['carGroup', 'extraCodes', 'media']);
        abort_unless($item->isApiVisible(), 404);
        $this->applySavedItemFlagToModel($item, $userId);

        $relatedQuery = Item::query()
            ->apiVisible()
            ->with(['carGroup', 'extraCodes', 'media'])
            ->where('car_group_id', $item->car_group_id)
            ->whereKeyNot($item->id)
            ->limit(5);

        $this->applySavedItemFlag($relatedQuery, $userId);

        $related = $relatedQuery->get();

        return response()->json([
            'data' => ItemResource::make($item)->resolve(),
            'related' => ItemResource::collection($related)->resolve(),
        ]);
    }

    public function similar(Request $request, Item $item): JsonResponse
    {
        $userId = $request->user('sanctum')?->getKey();

        $limit = max(1, min((int) $request->integer('limit', 8), 20));

        abort_unless($item->isApiVisible(), 404);

        $similarQuery = Item::query()
            ->apiVisible()
            ->with(['carGroup', 'extraCodes', 'media'])
            ->where('car_group_id', $item->car_group_id)
            ->whereKeyNot($item->id)
            ->orderByDesc('pt_ppm')
            ->limit($limit);

        $this->applySavedItemFlag($similarQuery, $userId);

        $similar = $similarQuery->get();

        return response()->json([
            'data' => ItemResource::collection($similar)->resolve(),
        ]);
    }

    private function applySavedItemFlag($query, int|string|null $userId): void
    {
        if (! $userId) {
            return;
        }

        $query->withExists([
            'savedByUsers as saved_item' => fn (Builder $builder) => $builder->where('users.id', $userId),
        ]);
    }

    private function applySavedItemFlagToModel(Item $item, int|string|null $userId): void
    {
        if (! $userId) {
            $item->setAttribute('saved_item', false);

            return;
        }

        $item->setAttribute(
            'saved_item',
            $item->savedByUsers()->where('users.id', $userId)->exists()
        );
    }
}
