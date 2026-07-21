<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\ItemFilterRequest;
use App\Http\Resources\API\ItemResource;
use App\Models\ExtraCode;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ItemController extends Controller
{
    public function index(ItemFilterRequest $request): JsonResponse
    {
        $userId = $request->user('sanctum')?->getKey();

        $itemsQuery = Item::getQuery($request);

        $this->applySavedItemFlag($itemsQuery, $userId);

        $items = $itemsQuery->paginate($request->integer('per_page', 20));

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

    public function codes(Request $request): JsonResponse
    {
        if (! $request->filled('search') && $request->filled('q')) {
            $request->merge(['search' => $request->input('q')]);
        }

        $validated = $request->validate([
            'search' => ['required', 'string', 'max:100'],
            'q' => ['sometimes', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $search = trim((string) $validated['search']);

        if ($search === '') {
            return response()->json(['data' => []]);
        }

        $limit = (int) ($validated['limit'] ?? 10);
        $candidateLimit = max($limit * 5, 50);
        $containsSearch = "%{$search}%";

        $itemCodes = Item::query()
            ->apiVisible()
            ->where(function (Builder $query) use ($containsSearch): void {
                $query->where('serial_code', 'like', $containsSearch)
                    ->orWhere('normalized_serial', 'like', $containsSearch);
            })
            ->orderBy('serial_code')
            ->limit($candidateLimit)
            ->pluck('serial_code');

        $extraCodes = ExtraCode::query()
            ->whereHas('item', static function (Builder $query): void {
                $query->apiVisible();
            })
            ->where('code', 'like', $containsSearch)
            ->orderBy('code')
            ->limit($candidateLimit)
            ->pluck('code');

        $normalizedSearch = Str::lower($search);

        $suggestions = $itemCodes
            ->merge($extraCodes)
            ->map(static fn (mixed $code): string => trim((string) $code))
            ->filter(static fn (string $code): bool => $code !== '')
            ->unique(static fn (string $code): string => Str::lower($code))
            ->sort(static function (string $left, string $right) use ($normalizedSearch): int {
                $leftStartsWithSearch = Str::startsWith(Str::lower($left), $normalizedSearch);
                $rightStartsWithSearch = Str::startsWith(Str::lower($right), $normalizedSearch);

                if ($leftStartsWithSearch !== $rightStartsWithSearch) {
                    return $leftStartsWithSearch ? -1 : 1;
                }

                return strnatcasecmp($left, $right);
            })
            ->take($limit)
            ->values();

        return response()->json([
            'data' => $suggestions->all(),
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
