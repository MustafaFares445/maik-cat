<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\ItemFilterRequest;
use App\Http\Resources\API\ItemResource;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class ItemController extends Controller
{
    public function index(ItemFilterRequest $request): JsonResponse
    {
        $userId = $request->user('sanctum')?->getKey();

        $itemsQuery = Item::getQuery()
            ->with('media')
            ->calculablePrice();

        $this->applySavedItemFlag($itemsQuery, $userId);

        $items = $itemsQuery->paginate($request->integer('per_page', 20));

        // #region agent log
        try {
            file_put_contents(base_path('debug-f25a9f.log'), json_encode([
                'sessionId' => 'f25a9f',
                'runId' => 'initial',
                'hypothesisId' => 'A,C',
                'location' => 'app/Http/Controllers/API/ItemController.php:28',
                'message' => 'Items index query returned calculable-price candidates',
                'data' => [
                    'count' => $items->count(),
                    'total' => $items->total(),
                    'currency' => $request->query('currency', 'USD'),
                    'sample' => $items->getCollection()->take(3)->map(fn (Item $item): array => [
                        'id' => $item->getKey(),
                        'weight_kg' => $item->weight_kg,
                        'pt_ppm' => $item->pt_ppm,
                        'pd_ppm' => $item->pd_ppm,
                        'rh_ppm' => $item->rh_ppm,
                    ])->values()->all(),
                ],
                'timestamp' => (int) round(microtime(true) * 1000),
            ], JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
        } catch (Throwable) {
        }
        // #endregion

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

        $item->load(['carGroup', 'extraCodes']);
        $this->applySavedItemFlagToModel($item, $userId);

        $relatedQuery = Item::query()
            ->calculablePrice()
            ->with(['carGroup', 'extraCodes'])
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

        $similarQuery = Item::query()
            ->calculablePrice()
            ->with(['carGroup', 'extraCodes'])
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
            'savedByUsers as saved_item' => fn(Builder $builder) => $builder->where('users.id', $userId),
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
