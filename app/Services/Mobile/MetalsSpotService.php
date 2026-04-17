<?php

namespace App\Services\Mobile;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MetalsSpotService
{
    private const float TROY_OZ_TO_GRAM = 31.1035;
    private const string CACHE_KEY = 'metals_spot_payload';
    private const string STALE_CACHE_KEY = 'metals_spot_payload_stale';

    /** @var array<string, array{name_en: string, name_ar: string, symbol: string}> */
    private array $meta = [
        'gold' => ['name_en' => 'Gold', 'name_ar' => 'ذهب', 'symbol' => 'Au'],
        'silver' => ['name_en' => 'Silver', 'name_ar' => 'فضة', 'symbol' => 'Ag'],
        'platinum' => ['name_en' => 'Platinum', 'name_ar' => 'بلاتين', 'symbol' => 'Pt'],
        'palladium' => ['name_en' => 'Palladium', 'name_ar' => 'بلاديوم', 'symbol' => 'Pd'],
        'rhodium' => ['name_en' => 'Rhodium', 'name_ar' => 'روديوم', 'symbol' => 'Rh'],
    ];

    public function __construct(private readonly CurrencyConversionService $currencyConversionService) {}

    /**
     * @return array{source: string, cached: bool, updated_at: string, data: array<int, array<string, mixed>>}
     */
    public function all(string $currency = 'USD'): array
    {
        $ttl = (int) config('services.metals.cache_ttl', 21600);

        if (Cache::has(self::CACHE_KEY)) {
            /** @var array{source: string, updated_at: string, data: array<int, array<string, mixed>>} $cached */
            $cached = Cache::get(self::CACHE_KEY);

            $payload = [
                ...$cached,
                'cached' => true,
            ];

            return $this->convertPayloadCurrency($payload, $currency);
        }

        $fresh = $this->fetchFresh();

        Cache::put(self::CACHE_KEY, [
            'source' => $fresh['source'],
            'updated_at' => $fresh['updated_at'],
            'data' => $fresh['data'],
        ], $ttl);

        return $this->convertPayloadCurrency($fresh, $currency);
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
     * @return array{source: string, cached: bool, updated_at: string, data: array<int, array<string, mixed>>}
     */
    public function refresh(string $currency = 'USD'): array
    {
        Cache::forget(self::CACHE_KEY);

        $fresh = $this->fetchFresh();

        $ttl = (int) config('services.metals.cache_ttl', 21600);

        Cache::put(self::CACHE_KEY, [
            'source' => $fresh['source'],
            'updated_at' => $fresh['updated_at'],
            'data' => $fresh['data'],
        ], $ttl);

        return $this->convertPayloadCurrency($fresh, $currency);
    }

    /**
     * @param  array{source: string, cached: bool, updated_at: string, data: array<int, array<string, mixed>>}  $payload
     * @return array{source: string, cached: bool, updated_at: string, currency: string, fx_rate: float, data: array<int, array<string, mixed>>}
     */
    private function convertPayloadCurrency(array $payload, string $currency): array
    {
        $currency = strtoupper(trim($currency));

        if ($currency !== 'EUR') {
            return [
                ...$payload,
                'currency' => 'USD',
                'fx_rate' => 1.0,
            ];
        }

        $rate = $this->currencyConversionService->rateOrOne('USD', 'EUR');
        $numericFields = ['price_oz', 'price_gram', 'change_oz'];

        $data = collect($payload['data'])
            ->map(function (array $row) use ($rate, $numericFields): array {
                foreach ($numericFields as $field) {
                    if (isset($row[$field]) && is_numeric($row[$field])) {
                        $row[$field] = round((float) $row[$field] * $rate, 2);
                    }
                }

                return $row;
            })
            ->values()
            ->all();

        return [
            ...$payload,
            'currency' => 'EUR',
            'fx_rate' => round($rate, 6),
            'data' => $data,
        ];
    }

    /**
     * @return array{source: string, cached: bool, updated_at: string, data: array<int, array<string, mixed>>}|null
     */
    private function fetchFromMetalsLive(): ?array
    {
        try {
            $response = Http::timeout((int) config('services.metals.timeout', 8))
                ->acceptJson()
                ->get((string) config('services.metals.live_url'));

            if (! $response->successful()) {
                return null;
            }

            $raw = $response->json();
            if (! is_array($raw) || empty($raw)) {
                return null;
            }

            $candidate = $this->extractFirstPriceMap($raw);
            if ($candidate === []) {
                return null;
            }

            return $this->normalise($candidate, 'api.metals.live');
        } catch (Throwable $exception) {
            Log::warning('Metals live fetch failed', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * @return array{source: string, cached: bool, updated_at: string, data: array<int, array<string, mixed>>}|null
     */
    private function fetchFromKitco(): ?array
    {
        try {
            $response = Http::timeout((int) config('services.metals.timeout', 8))
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; MaikCarsMetalsWidget/1.0)',
                    'Accept' => 'text/plain,text/html;q=0.9,*/*;q=0.8',
                ])
                ->get((string) config('services.metals.kitco_url'));

            if (! $response->successful()) {
                return null;
            }

            $parsed = $this->parseKitcoQuotes($response->body());

            if ($parsed === []) {
                return null;
            }

            return $this->normalise($parsed, 'kitco.com');
        } catch (Throwable $exception) {
            Log::warning('Kitco fetch failed', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFirstPriceMap(array $payload): array
    {
        if (array_is_list($payload)) {
            foreach ($payload as $entry) {
                if (! is_array($entry) || empty($entry)) {
                    continue;
                }

                $candidate = $this->normalizeMetalsLiveRow($entry);
                if ($candidate !== []) {
                    return $candidate;
                }
            }

            return [];
        }

        return $this->normalizeMetalsLiveRow($payload);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeMetalsLiveRow(array $row): array
    {
        $normalized = [];

        foreach ($this->meta as $metal => $_meta) {
            if (array_key_exists($metal, $row)) {
                $normalized[$metal] = $row[$metal];
                continue;
            }

            $symbol = strtolower($_meta['symbol']);

            if (array_key_exists($symbol, $row)) {
                $normalized[$metal] = $row[$symbol];
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, array{price: float, bid: float, ask: float, change: float, change_pct: float}>
     */
    private function parseKitcoQuotes(string $body): array
    {
        $text = strtolower(strip_tags($body));
        $metals = array_keys($this->meta);
        $map = [];

        foreach ($metals as $metal) {
            $pattern = '/\b' . preg_quote($metal, '/')
                . '\b\s+(-?[\d,]+(?:\.\d+)?)'
                . '\s+(-?[\d,]+(?:\.\d+)?)'
                . '\s+(-?[\d,]+(?:\.\d+)?)'
                . '\s+(-?[\d,.]+)%/i';

            if (! preg_match($pattern, $text, $matches)) {
                continue;
            }

            $bid = (float) str_replace(',', '', $matches[1]);
            $ask = (float) str_replace(',', '', $matches[2]);
            $change = (float) str_replace(',', '', $matches[3]);
            $changePct = (float) str_replace(',', '', $matches[4]);

            $map[$metal] = [
                'price' => round(($bid + $ask) / 2, 2),
                'bid' => $bid,
                'ask' => $ask,
                'change' => $change,
                'change_pct' => $changePct,
            ];
        }

        return $map;
    }

    /**
     * @return array{source: string, cached: bool, updated_at: string, data: array<int, array<string, mixed>>}
     */
    private function fetchFresh(): array
    {
        $result = $this->fetchFromMetalsLive()
            ?? $this->fetchFromKitco();

        if ($result !== null) {
            Cache::forever(self::STALE_CACHE_KEY, [
                'source' => $result['source'],
                'updated_at' => $result['updated_at'],
                'data' => $result['data'],
            ]);

            return $result;
        }

        if ((bool) config('services.metals.fallback', true)) {
            $stale = Cache::get(self::STALE_CACHE_KEY);

            if (is_array($stale) && isset($stale['data'])) {
                return [
                    'source' => 'stale_cache',
                    'cached' => true,
                    'updated_at' => (string) ($stale['updated_at'] ?? now()->toIso8601String()),
                    'data' => $stale['data'],
                ];
            }
        }

        throw new RuntimeException('All price sources are currently unreachable.');
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{source: string, cached: bool, updated_at: string, data: array<int, array<string, mixed>>}
     */
    private function normalise(array $raw, string $source): array
    {
        $rows = [];

        foreach ($this->meta as $key => $meta) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }

            $entry = $raw[$key];

            if (is_numeric($entry)) {
                $priceOz = (float) $entry;
                $changeOz = 0.0;
                $changePct = 0.0;
            } else {
                $priceOz = (float) ($entry['price'] ?? $entry['ask'] ?? 0.0);
                $changeOz = (float) ($entry['change'] ?? 0.0);
                $changePct = (float) ($entry['change_pct'] ?? 0.0);
            }

            $rows[] = [
                'key' => $key,
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
            'source' => $source,
            'cached' => false,
            'updated_at' => now()->toIso8601String(),
            'data' => $rows,
        ];
    }
}
