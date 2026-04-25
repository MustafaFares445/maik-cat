<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\CalculatorEstimateRequest;
use App\Models\MetalPrice;
use App\Services\Mobile\CalculatorService;
use Illuminate\Http\JsonResponse;

class CalculatorController extends Controller
{
    public function __construct(private readonly CalculatorService $calculatorService) {}

    public function estimate(CalculatorEstimateRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $metalPrice = MetalPrice::query()->latest('fetched_at')->first();

        if (! $metalPrice) {
            return response()->json([
                'message' => 'No metal prices are available for calculation.',
            ], 422);
        }

        $estimate = $this->calculatorService->estimate(
            weight: (float) $payload['weight'],
            ptPpm: (float) $payload['ptPpm'],
            pdPpm: (float) $payload['pdPpm'],
            rhPpm: (float) $payload['rhPpm'],
            metalPrice: $metalPrice,
            recoveryRate: (float) $payload['recoveryRate'],
            currency: $request->currency(),
        );

        return response()->json([
            'estimate' => $estimate,
        ]);
    }
}
