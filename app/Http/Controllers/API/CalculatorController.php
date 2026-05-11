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
            weightUnit: (string) ($payload['weightUnit'] ?? 'g'),
            ptUsdPerGram: isset($payload['ptUsdPerGram']) ? (float) $payload['ptUsdPerGram'] : null,
            pdUsdPerGram: isset($payload['pdUsdPerGram']) ? (float) $payload['pdUsdPerGram'] : null,
            rhUsdPerGram: isset($payload['rhUsdPerGram']) ? (float) $payload['rhUsdPerGram'] : null,
            ptRate: isset($payload['ptRate']) ? (float) $payload['ptRate'] : null,
            pdRate: isset($payload['pdRate']) ? (float) $payload['pdRate'] : null,
            rhRate: isset($payload['rhRate']) ? (float) $payload['rhRate'] : null,
            humidityRate: (float) ($payload['humidityRate'] ?? 0),
            currency: $request->currency(),
        );

        return response()->json([
            'estimate' => $estimate,
        ]);
    }
}
