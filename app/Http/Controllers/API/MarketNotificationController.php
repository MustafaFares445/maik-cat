<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\MarketChangesRequest;
use App\Services\Mobile\ThirdPartyMarketService;
use Illuminate\Http\JsonResponse;

class MarketNotificationController extends Controller
{
    public function __construct(private readonly ThirdPartyMarketService $marketService) {}

    public function index(MarketChangesRequest $request): JsonResponse
    {
        $changes = collect($this->marketService->changes($request->days(), $request->currency()));

        $notifications = $changes
            ->reverse()
            ->values()
            ->map(fn(array $item, int $index) => $this->toNotification($item, $index));

        return response()->json([
            'currency' => $request->currency(),
            'data' => $notifications,
        ]);
    }

    private function toNotification(array $item, int $index): array
    {
        return [
            'id' => (string) ($item['date'] ?? now()->toDateString()) . '-' . $index,
            'type' => 'market_change',
            'title' => 'Metal prices updated',
            'body' => sprintf(
                'Pt %.2f%%, Pd %.2f%%, Rh %.2f%%',
                (float) ($item['pt_change_percent'] ?? 0),
                (float) ($item['pd_change_percent'] ?? 0),
                (float) ($item['rh_change_percent'] ?? 0),
            ),
            'date' => $item['date'] ?? null,
            'meta' => [
                'pt_usd_per_oz' => $item['pt_usd_per_oz'] ?? null,
                'pd_usd_per_oz' => $item['pd_usd_per_oz'] ?? null,
                'rh_usd_per_oz' => $item['rh_usd_per_oz'] ?? null,
                'pt_eur_per_oz' => $item['pt_eur_per_oz'] ?? null,
                'pd_eur_per_oz' => $item['pd_eur_per_oz'] ?? null,
                'rh_eur_per_oz' => $item['rh_eur_per_oz'] ?? null,
                'currency' => $item['currency'] ?? 'USD',
                'fx_rate' => $item['fx_rate'] ?? 1.0,
            ],
        ];
    }
}
