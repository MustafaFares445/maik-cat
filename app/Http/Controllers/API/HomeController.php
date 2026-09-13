<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\HomeStatsRequest;
use App\Http\Resources\API\ItemResource;
use App\Models\Item;
use App\Services\Mobile\ItemApiResponseService;
use App\Services\Mobile\ThirdPartyMarketService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __construct(
        private ThirdPartyMarketService $marketService,
        private readonly ItemApiResponseService $itemApiResponseService,
    ) {}

    public function stats(HomeStatsRequest $request): JsonResponse
    {
        $stats = $this->marketService->homepageStats($request->currency());

        return response()->json([
            'stats' => $stats,
        ]);
    }

    public function topItems(Request $request): JsonResponse
    {
        $userId = $request->user('sanctum')?->getKey();
        $uniqueMode = $this->itemApiResponseService->uniqueModeEnabled();

        $topConvertersQuery = Item::query()
            ->apiVisible()
            ->with(['carGroup', 'extraCodes', 'media'])
            ->latest()
            ->limit($uniqueMode ? 30 : 6);

        if ($userId) {
            $topConvertersQuery->withExists([
                'savedByUsers as saved_item' => fn (Builder $builder) => $builder->where('users.id', $userId),
            ]);
        }

        $topItems = $this->itemApiResponseService
            ->transform($topConvertersQuery->get())
            ->take(6)
            ->values();

        return response()->json([
            'top_items' => ItemResource::collection($topItems)->resolve(),
        ]);
    }
}
