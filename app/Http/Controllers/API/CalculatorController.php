<?php

namespace App\Http\Controllers\API;

use App\Data\CalculatorEstimateData;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\CalculatorEstimateRequest;
use App\Models\Item;
use App\Models\MetalPrice;
use App\Services\Mobile\CalculatorService;
use Illuminate\Http\JsonResponse;

class CalculatorController extends Controller
{
    public function __construct(private readonly CalculatorService $calculatorService) {}

    public function estimate(CalculatorEstimateRequest $request): JsonResponse
    {
        $payload = CalculatorEstimateData::from($request->validated());

        $item = Item::query()
            ->with(['carGroup', 'extraCodes'])
            ->findOrFail($payload->item_id);

        $metalPrice = MetalPrice::query()->latest('fetched_at')->first();

        if (! $metalPrice) {
            return response()->json([
                'message' => 'No metal prices are available for calculation.',
            ], 422);
        }

        $estimate = $this->calculatorService->estimate(
            item: $item,
            metalPrice: $metalPrice,
            recoveryRate: (float) ($payload->recovery_rate ?? 0.8),
            currency: $request->currency(),
        );

        return response()->json([
            'item' => [
                'id' => $item->id,
                'serial_code' => $item->serial_code,
                'model' => $item->model,
            ],
            'estimate' => $estimate,
        ]);
    }
}
