<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\MetalsSpotRequest;
use App\Services\Mobile\MetalsResponseService;
use App\Services\Mobile\MetalsSpotService;
use Illuminate\Http\JsonResponse;

class MetalsController extends Controller
{
    public function __construct(
        private readonly MetalsSpotService $metalsService,
        private readonly MetalsResponseService $responseService,
    ) {}

    public function index(MetalsSpotRequest $request): JsonResponse
    {
        try {
            $result = $this->metalsService->all($request->currency());
            $ttl = (int) config('services.metals.cache_ttl', 21600);
            $payload = $this->responseService->indexPayload($result, $request);

            return response()->json($payload)->header('Cache-Control', "public, max-age={$ttl}");
        } catch (\RuntimeException $exception) {
            return response()->json($this->responseService->upstreamUnavailablePayload($exception->getMessage()), 503);
        }
    }

    public function show(string $key): JsonResponse
    {
        try {
            $currency = strtoupper((string) request()->query('currency', 'USD'));
            $metal = $this->metalsService->find($key, $currency);

            if (! $metal) {
                return response()->json($this->responseService->notFoundPayload($key), 404);
            }

            $all = $this->metalsService->all($currency);

            return response()->json($this->responseService->showPayload($all, $metal));
        } catch (\RuntimeException $exception) {
            return response()->json($this->responseService->upstreamUnavailablePayload($exception->getMessage()), 503);
        }
    }

    public function refresh(): JsonResponse
    {
        try {
            $result = $this->metalsService->refresh();

            return response()->json($this->responseService->refreshPayload($result));
        } catch (\RuntimeException $exception) {
            return response()->json($this->responseService->upstreamUnavailablePayload($exception->getMessage()), 503);
        }
    }
}
