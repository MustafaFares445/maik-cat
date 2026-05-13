<?php

namespace App\Services\Mobile;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MetalsSpotService
{
    private const float TROY_OZ_TO_GRAM = 31.1035;

    public const string UPSTREAM_UNAVAILABLE_MESSAGE = 'The metals pricing service is temporarily unavailable.';

    private const string SOURCE = 'metal-sentinel';

    /** @var array<string, array{name_en: string, name_ar: string, symbol: string, api_symbol: string}> */
    private array $meta = [
        'platinum' => ['name_en' => 'Platinum', 'name_ar' => 'بلاتين', 'symbol' => 'Pt', 'api_symbol' => 'PT'],
        'palladium' => ['name_en' => 'Palladium', 'name_ar' => 'بلاديوم', 'symbol' => 'Pd', 'api_symbol' => 'PD'],
        'rhodium' => ['name_en' => 'Rhodium', 'name_ar' => 'روديوم', 'symbol' => 'Rh', 'api_symbol' => 'RH'],
    ];

    /**
     * @return array{source: string, cached: bool, updated_at: string, currency: string, fx_rate: float, data: array<int, array<string, mixed>>}
     */
    public function all(string $currency = 'USD'): array
    {
        $currency = strtoupper(trim($currency));
        $ttl = (int) config('services.metals.cache_ttl', 60);
        $cacheKey = $this->cacheKey($currency);

        $hit = Cache::has($cacheKey);

        try {
            $payload = Cache::remember($cacheKey, $ttl, fn (): array => $this->fetchFreshPayload($currency));
        } catch (Throwable $e) {
            Cache::forget($cacheKey);
            throw $e;
        }

        return [
            ...$payload,
            'cached' => $hit,
            'currency' => $currency,
            'fx_rate' => 1.0,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key, string $currency = 'USD'): ?array
    {
        $normalizedKey = strtolower(trim($key));
        $result = $this->all($currency);

        return collect($result['data'])->firstWhere('key', $normalizedKey);
    }

    /**
     * @return array{source: string, cached: bool, updated_at: string, currency: string, fx_rate: float, data: array<int, array<string, mixed>>}
     */
    public function refresh(string $currency = 'USD'): array
    {
        foreach (['USD', 'EUR'] as $cur) {
            Cache::forget($this->cacheKey($cur));
        }

        return $this->all($currency);
    }

    private function cacheKey(string $currency): string
    {
        return 'metals_spot_sentinel_v2:'.strtoupper($currency);
    }

    /**
     * @return array{source: string, updated_at: string, data: array<int, array<string, mixed>>}
     */
    private function fetchFreshPayload(string $currency): array
    {
        $apiKey = (string) config('services.metals.rapidapi_key', '');
        $baseUrl = rtrim((string) config('services.metals.base_url', 'https://metal-sentinel.p.rapidapi.com'), '/');

        if ($apiKey === '') {
            Log::warning('Metal Sentinel API key is not configured');

            throw new RuntimeException(self::UPSTREAM_UNAVAILABLE_MESSAGE);
        }

        $timeout = (int) config('services.metals.timeout', 8);
        // RapidAPI hub exposes this route as /metal-quote (not /api/metal-quote); see 404 "does not exist" on /api/...
        $quoteUrl = $baseUrl.'/metal-quote';
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: 'metal-sentinel.p.rapidapi.com';

        try {
            /** @var array<string, Response> $responses */
            $responses = Http::pool(function (Pool $pool) use ($quoteUrl, $currency, $timeout, $apiKey, $host): array {
                $pending = [];

                foreach ($this->meta as $metalKey => $meta) {
                    $pending[] = $pool->as($metalKey)
                        ->withHeaders([
                            'X-RapidAPI-Key' => $apiKey,
                            'X-RapidAPI-Host' => $host,
                        ])
                        ->timeout($timeout)
                        ->acceptJson()
                        ->get($quoteUrl, [
                            'symbol' => $meta['api_symbol'],
                            'currency' => $currency,
                        ]);
                }

                return $pending;
            });
        } catch (Throwable $e) {
            throw new RuntimeException(self::UPSTREAM_UNAVAILABLE_MESSAGE);
        }

        $rows = [];

        foreach ($this->meta as $metalKey => $meta) {
            $response = $responses[$metalKey] ?? null;

            if (! $response instanceof Response || ! $response->successful()) {
                Log::warning('Metal Sentinel quote request failed', [
                    'metal' => $metalKey,
                    'status' => $response instanceof Response ? $response->status() : null,
                ]);

                throw new RuntimeException(self::UPSTREAM_UNAVAILABLE_MESSAGE);
            }

            $json = $response->json();

            if (! is_array($json)) {
                throw new RuntimeException(self::UPSTREAM_UNAVAILABLE_MESSAGE);
            }

            [$priceOz, $changeOz, $changePct] = $this->extractSpotMetrics($json);

            if ($priceOz <= 0.0) {
                throw new RuntimeException(self::UPSTREAM_UNAVAILABLE_MESSAGE);
            }

            $rows[] = [
                'key' => $metalKey,
                'name_en' => $meta['name_en'],
                'name_ar' => $meta['name_ar'],
                'symbol' => $meta['symbol'],
                'price_oz' => round($priceOz, 2),
                'price_gram' => round($priceOz / self::TROY_OZ_TO_GRAM, 2),
                'change_oz' => round($changeOz, 2),
                'change_pct' => round($changePct, 2),
                'direction' => $changePct > 0 ? 'up' : ($changePct < 0 ? 'down' : 'flat'),
            ];
        }

        return [
            'source' => self::SOURCE,
            'updated_at' => now()->toIso8601String(),
            'data' => $rows,
        ];
    }

    /**
     * RapidAPI may return the quote flatly or wrapped in `data` / `results` (e.g. ID + results envelope).
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function resolveQuotePayloadNode(array $json): array
    {
        foreach (['data', 'results'] as $wrapper) {
            if (! isset($json[$wrapper]) || ! is_array($json[$wrapper])) {
                continue;
            }

            $inner = $json[$wrapper];

            if (array_is_list($inner)) {
                $first = $inner[0] ?? null;

                if (is_array($first)) {
                    return $first;
                }

                continue;
            }

            return $inner;
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{0: float, 1: float, 2: float}
     */
    private function extractSpotMetrics(array $json): array
    {
        $node = $this->resolveQuotePayloadNode($json);

        $price = $this->extractSpotPriceOz($node);

        $changeOz = $this->extractChangeOz($node);
        $changePct = $this->firstNumeric($node, ['changePercent', 'percentChange', 'change_pct', 'pctChange', 'changePercentage']) ?? 0.0;

        if ($price === null) {
            throw new RuntimeException(self::UPSTREAM_UNAVAILABLE_MESSAGE);
        }

        return [(float) $price, (float) $changeOz, (float) $changePct];
    }

    /**
     * Spot USD/oz uses upstream bid only (troy oz), including common nested envelopes.
     *
     * @param  array<string, mixed>  $node
     */
    private function extractSpotPriceOz(array $node): ?float
    {
        $bid = $this->firstPositiveNumeric($node, ['bid']);

        if ($bid !== null) {
            return $bid;
        }

        foreach (['quote', 'payload', 'spot', 'item', 'result', 'body'] as $nest) {
            if (! isset($node[$nest]) || ! is_array($node[$nest])) {
                continue;
            }

            $bid = $this->firstPositiveNumeric($node[$nest], ['bid']);

            if ($bid !== null) {
                return $bid;
            }
        }

        return null;
    }

    /**
     * Per-ounce change amount from upstream `change` only (may be negative), including nested envelopes.
     *
     * @param  array<string, mixed>  $node
     */
    private function extractChangeOz(array $node): float
    {
        $change = $this->firstNumeric($node, ['change']);

        if ($change !== null) {
            return $change;
        }

        foreach (['quote', 'payload', 'spot', 'item', 'result', 'body'] as $nest) {
            if (! isset($node[$nest]) || ! is_array($node[$nest])) {
                continue;
            }

            $change = $this->firstNumeric($node[$nest], ['change']);

            if ($change !== null) {
                return $change;
            }
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     */
    private function firstPositiveNumeric(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if (! is_numeric($value)) {
                continue;
            }

            $float = (float) $value;

            if ($float > 0.0) {
                return $float;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     */
    private function firstNumeric(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }
}
