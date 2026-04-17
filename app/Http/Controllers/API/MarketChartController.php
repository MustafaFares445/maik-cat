<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\MarketChangesRequest;
use App\Services\Mobile\ThirdPartyMarketService;
use Illuminate\Http\JsonResponse;

class MarketChartController extends Controller
{
    public function __construct(private readonly ThirdPartyMarketService $marketService) {}

    public function index(MarketChangesRequest $request): JsonResponse
    {
        $changes = $this->marketService->changes($request->days(), $request->currency());

        return response()->json([
            'period' => '14_days',
            'currency' => $request->currency(),
            'points' => $changes,
        ]);
    }
}
