<?php

namespace App\Services\Mobile;

use App\Http\Requests\API\MetalsSpotRequest;

class MetalsResponseService
{
    /**
     * @param  array{source: string, cached: bool, updated_at: string, currency?: string, fx_rate?: float, data: array<int, array<string, mixed>>}  $result
     * @return array{success: bool, source: string, cached: bool, cache_expires_at: string, updated_at: string, currency: string, fx_rate: float, data: array<int, array<string, mixed>>}
     */
    public function indexPayload(array $result, MetalsSpotRequest $request): array
    {
        $data = $this->filterMetals($result['data'], $request->requestedMetals());
        $data = $this->filterUnitColumns($data, $request->unit());

        $ttl = (int) config('services.metals.cache_ttl', 21600);

        return [
            'success' => true,
            'source' => $result['source'],
            'cached' => (bool) $result['cached'],
            'cache_expires_at' => now()->addSeconds($ttl)->toIso8601String(),
            'updated_at' => $result['updated_at'],
            'currency' => $result['currency'] ?? 'USD',
            'fx_rate' => $result['fx_rate'] ?? 1.0,
            'data' => $data,
        ];
    }

    /**
     * @param  array{source: string, cached: bool, updated_at: string, currency?: string, fx_rate?: float}  $result
     * @param  array<string, mixed>  $metal
     * @return array{success: bool, source: string, cached: bool, currency: string, fx_rate: float, data: array<string, mixed>}
     */
    public function showPayload(array $result, array $metal): array
    {
        return [
            'success' => true,
            'source' => $result['source'],
            'cached' => (bool) $result['cached'],
            'currency' => $result['currency'] ?? 'USD',
            'fx_rate' => $result['fx_rate'] ?? 1.0,
            'data' => $metal,
        ];
    }

    /**
     * @param  array{source: string, updated_at: string}  $result
     * @return array{success: bool, message: string, source: string, updated_at: string}
     */
    public function refreshPayload(array $result): array
    {
        return [
            'success' => true,
            'message' => 'Cache cleared. Fresh data fetched.',
            'source' => $result['source'],
            'updated_at' => $result['updated_at'],
        ];
    }

    /**
     * @return array{success: false, error: string, message: string, retry_after: int}
     */
    public function upstreamUnavailablePayload(string $message): array
    {
        return [
            'success' => false,
            'error' => 'upstream_unavailable',
            'message' => $message,
            'retry_after' => 300,
        ];
    }

    /**
     * @return array{success: false, error: string, message: string}
     */
    public function notFoundPayload(string $key): array
    {
        return [
            'success' => false,
            'error' => 'not_found',
            'message' => "Metal '{$key}' is not supported.",
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $requestedMetals
     * @return array<int, array<string, mixed>>
     */
    private function filterMetals(array $rows, array $requestedMetals): array
    {
        if ($requestedMetals === []) {
            return $rows;
        }

        return collect($rows)
            ->whereIn('key', $requestedMetals)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterUnitColumns(array $rows, string $unit): array
    {
        if ($unit === 'oz') {
            return collect($rows)
                ->map(function (array $row): array {
                    unset($row['price_gram']);

                    return $row;
                })
                ->values()
                ->all();
        }

        if ($unit === 'gram') {
            return collect($rows)
                ->map(function (array $row): array {
                    unset($row['price_oz']);

                    return $row;
                })
                ->values()
                ->all();
        }

        return $rows;
    }
}
