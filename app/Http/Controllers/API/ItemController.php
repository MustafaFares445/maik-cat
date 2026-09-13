<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\ItemFilterRequest;
use App\Http\Resources\API\ItemResource;
use App\Models\ExtraCode;
use App\Models\Item;
use App\Services\Mobile\ItemApiResponseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class ItemController extends Controller
{
    public function __construct(
        private readonly ItemApiResponseService $itemApiResponseService,
    ) {}

    public function index(ItemFilterRequest $request): JsonResponse
    {
        $userId = $request->user('sanctum')?->getKey();
        $perPage = $request->integer('per_page', 20);

        if (! $this->itemApiResponseService->uniqueModeEnabled()) {
            $itemsQuery = Item::getQuery($request);

            $this->applySavedItemFlag($itemsQuery, $userId);

            return $this->paginatedResponse($itemsQuery->paginate($perPage));
        }

        $orderedRows = Item::getQuery($request, withRelations: false)
            ->get(['items.id', 'items.normalized_serial', 'items.serial_code']);
        $representativeIds = $this->itemApiResponseService->representativeIds($orderedRows);

        $page = max(1, $request->integer('page', 1));
        $pageIds = $representativeIds->forPage($page, $perPage)->values();

        $pageQuery = Item::query()
            ->with(['carGroup', 'media'])
            ->whereKey($pageIds->all());

        $this->applySavedItemFlag($pageQuery, $userId);

        $itemsById = $pageQuery
            ->get()
            ->keyBy(fn (Item $item): string => (string) $item->getKey());

        $pageItems = $pageIds
            ->map(fn (mixed $id): ?Item => $itemsById->get((string) $id))
            ->filter(fn (mixed $item): bool => $item instanceof Item)
            ->values();

        $pageItems = $this->itemApiResponseService->applyAverages($pageItems);

        $items = new LengthAwarePaginator(
            $pageItems,
            $representativeIds->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );

        return $this->paginatedResponse($items);
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

        $uniqueMode = $this->itemApiResponseService->uniqueModeEnabled();
        $serial = Item::normalizeSerialValue($item->normalized_serial ?: $item->serial_code);

        $relatedQuery = Item::query()
            ->apiVisible()
            ->with(['carGroup', 'extraCodes', 'media'])
            ->where('car_group_id', $item->car_group_id)
            ->whereKeyNot($item->id);

        if ($uniqueMode && $serial !== '') {
            $relatedQuery->where('normalized_serial', '!=', $serial);
        }

        $relatedQuery->limit($uniqueMode ? 25 : 5);

        $this->applySavedItemFlag($relatedQuery, $userId);

        $responseItem = $this->itemApiResponseService
            ->transform(collect([$item]))
            ->first() ?? $item;
        $related = $this->itemApiResponseService
            ->transform($relatedQuery->get())
            ->take(5)
            ->values();

        return response()->json([
            'data' => ItemResource::make($responseItem)->resolve(),
            'related' => ItemResource::collection($related)->resolve(),
        ]);
    }

    public function similar(Request $request, Item $item): JsonResponse
    {
        $userId = $request->user('sanctum')?->getKey();

        $limit = max(1, min((int) $request->integer('limit', 8), 20));

        abort_unless($item->isApiVisible(), 404);

        $uniqueMode = $this->itemApiResponseService->uniqueModeEnabled();
        $serial = Item::normalizeSerialValue($item->normalized_serial ?: $item->serial_code);

        $similarQuery = Item::query()
            ->apiVisible()
            ->with(['carGroup', 'extraCodes', 'media'])
            ->where('car_group_id', $item->car_group_id)
            ->whereKeyNot($item->id)
            ->orderByDesc('pt_ppm');

        if ($uniqueMode && $serial !== '') {
            $similarQuery->where('normalized_serial', '!=', $serial);
        }

        $similarQuery->limit($uniqueMode ? min($limit * 5, 100) : $limit);

        $this->applySavedItemFlag($similarQuery, $userId);

        $similar = $this->itemApiResponseService
            ->transform($similarQuery->get())
            ->take($limit)
            ->values();

        return response()->json([
            'data' => ItemResource::collection($similar)->resolve(),
        ]);
    }

    private function paginatedResponse(LengthAwarePaginator $items): JsonResponse
    {
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
