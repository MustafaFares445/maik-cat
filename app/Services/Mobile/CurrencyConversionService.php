<?php

namespace App\Services\Mobile;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CurrencyConversionService
{
    private const string CACHE_PREFIX = 'fx_rate_';

    public function rateOrOne(string $from, string $to): float
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        if ($from === $to) {
            return 1.0;
        }

        $cacheKey = self::CACHE_PREFIX . strtolower($from . '_' . $to);
        $ttl = (int) config('services.currency.cache_ttl', 1800);

        return (float) Cache::remember($cacheKey, $ttl, function () use ($from, $to): float {
            $endpoint = (string) config('services.currency.public_url', 'https://api.frankfurter.app/latest');

            try {
                $response = Http::timeout((int) config('services.currency.timeout', 6))
                    ->acceptJson()
                    ->get($endpoint, [
                        'from' => $from,
                        'to' => $to,
                    ]);

                if (! $response->successful()) {
                    return 1.0;
                }

                $payload = $response->json();
                $rate = is_array($payload) ? ($payload['rates'][$to] ?? null) : null;

                return is_numeric($rate) ? (float) $rate : 1.0;
            } catch (Throwable $exception) {
                Log::warning('Currency conversion fetch failed', [
                    'from' => $from,
                    'to' => $to,
                    'error' => $exception->getMessage(),
                ]);

                return 1.0;
            }
        });
    }

    public function convert(float $amount, string $from, string $to, int $precision = 2): float
    {
        return round($amount * $this->rateOrOne($from, $to), $precision);
    }
}
