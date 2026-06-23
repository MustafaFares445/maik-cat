<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\HomeStatsRequest;
use App\Http\Resources\API\ItemResource;
use App\Models\Item;
use App\Services\Mobile\ThirdPartyMarketService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __construct(private ThirdPartyMarketService $marketService) {}

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

        $topConvertersQuery = Item::query()
            ->calculablePrice()
            ->with(['carGroup', 'extraCodes' , 'media'])
            ->latest()
            ->limit(6);

        if ($userId) {
            $topConvertersQuery->withExists([
                'savedByUsers as saved_item' => fn(Builder $builder) => $builder->where('users.id', $userId),
            ]);
        }

        $topItems = $topConvertersQuery->get();

        return response()->json([
            'top_items' => ItemResource::collection($topItems)->resolve(),
        ]);
    }
}
