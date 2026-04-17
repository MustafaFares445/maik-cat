<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\HomeStatsRequest;
use App\Http\Resources\API\ItemResource;
use App\Models\Item;
use App\Services\Mobile\ThirdPartyMarketService;
use Illuminate\Http\JsonResponse;

class HomeController extends Controller
{
    public function __construct(private readonly ThirdPartyMarketService $marketService) {}

    public function stats(HomeStatsRequest $request): JsonResponse
    {
        $days = $request->days();
        $stats = $this->marketService->homepageStats($days, $request->currency());

        return response()->json([
            'stats' => $stats,
        ]);
    }

    public function topItems(): JsonResponse
    {
        $topConverters = Item::query()
            ->with(['carGroup', 'extraCodes'])
            ->latest()
            ->limit(6)
            ->get();

        return response()->json([
            'top_items' => ItemResource::collection($topConverters)->resolve(),
        ]);
    }
}
