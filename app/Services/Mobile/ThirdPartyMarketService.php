<?php

namespace App\Services\Mobile;

use App\Models\MetalPrice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

class ThirdPartyMarketService
{
    public function __construct(private readonly CurrencyConversionService $currencyConversionService) {}

    public function changes(int $days = 14, string $currency = 'USD'): array
    {
        $days = max(1, min($days, 14));
        $url = (string) config('services.market_feed.changes_url');

        if ($url === '') {
            return $this->convertChangesCurrency($this->fallbackChanges($days), $currency);
        }

        try {
            $response = Http::retry(2, 200)
                ->timeout((int) config('services.market_feed.timeout', 10))
                ->acceptJson()
                ->withToken((string) config('services.market_feed.token'))
                ->get($url, ['days' => $days]);

            if (! $response->successful()) {
                return $this->convertChangesCurrency($this->fallbackChanges($days), $currency);
            }

            return $this->convertChangesCurrency($this->normalizeChanges($response->json(), $days), $currency);
        } catch (Throwable) {
            return $this->convertChangesCurrency($this->fallbackChanges($days), $currency);
        }
    }

    public function homepageStats(int $days = 14, string $currency = 'USD'): array
    {
        $changes = $this->changes($days, $currency);

        return [
            'source' => config('services.market_feed.changes_url') ? 'third_party' : 'fallback',
            'currency' => strtoupper(trim($currency)) === 'EUR' ? 'EUR' : 'USD',
            'changes' => $changes,
            'summary' => $this->buildSummary(collect($changes)->last() ?: []),
        ];
    }

    private function convertChangesCurrency(array $changes, string $currency): array
    {
        if (strtoupper(trim($currency)) !== 'EUR') {
            return $changes;
        }

        $rate = $this->currencyConversionService->rateOrOne('USD', 'EUR');

        return collect($changes)
            ->map(function (array $row) use ($rate): array {
                $ptUsd = (float) ($row['pt_usd_per_oz'] ?? 0.0);
                $pdUsd = (float) ($row['pd_usd_per_oz'] ?? 0.0);
                $rhUsd = (float) ($row['rh_usd_per_oz'] ?? 0.0);

                return [
                    ...$row,
                    'pt_eur_per_oz' => round($ptUsd * $rate, 4),
                    'pd_eur_per_oz' => round($pdUsd * $rate, 4),
                    'rh_eur_per_oz' => round($rhUsd * $rate, 4),
                    'currency' => 'EUR',
                    'fx_rate' => round($rate, 6),
                ];
            })
            ->values()
            ->all();
    }

    private function normalizeChanges(mixed $payload, int $days): array
    {
        $rows = collect();

        if (is_array($payload)) {
            $rows = collect($payload['changes'] ?? $payload);
        }

        $normalized = $rows
            ->map(fn(mixed $row) => is_array($row) ? $row : [])
            ->filter(fn(array $row) => ! empty($row))
            ->values()
            ->map(function (array $row): array {
                return [
                    'date' => (string) ($row['date'] ?? $row['changed_at'] ?? $row['timestamp'] ?? now()->toDateString()),
                    'pt_usd_per_oz' => (float) ($row['pt_usd_per_oz'] ?? $row['pt_price'] ?? 0),
                    'pd_usd_per_oz' => (float) ($row['pd_usd_per_oz'] ?? $row['pd_price'] ?? 0),
                    'rh_usd_per_oz' => (float) ($row['rh_usd_per_oz'] ?? $row['rh_price'] ?? 0),
                    'pt_change_percent' => $this->nullableFloat($row['pt_change_percent'] ?? $row['pt_change'] ?? null),
                    'pd_change_percent' => $this->nullableFloat($row['pd_change_percent'] ?? $row['pd_change'] ?? null),
                    'rh_change_percent' => $this->nullableFloat($row['rh_change_percent'] ?? $row['rh_change'] ?? null),
                ];
            })
            ->sortBy('date')
            ->values();

        return $this->fillMissingChanges($normalized)->take(-$days)->values()->all();
    }

    private function fallbackChanges(int $days): array
    {
        $rows = MetalPrice::query()
            ->where('fetched_at', '>=', now()->subDays($days + 2)->startOfDay())
            ->orderBy('fetched_at')
            ->get()
            ->groupBy(fn(MetalPrice $price) => $price->fetched_at->toDateString())
            ->map(fn(Collection $group) => $group->last())
            ->values()
            ->map(function (MetalPrice $price): array {
                return [
                    'date' => $price->fetched_at->toDateString(),
                    'pt_usd_per_oz' => (float) $price->pt_usd_per_oz,
                    'pd_usd_per_oz' => (float) $price->pd_usd_per_oz,
                    'rh_usd_per_oz' => (float) $price->rh_usd_per_oz,
                    'pt_change_percent' => null,
                    'pd_change_percent' => null,
                    'rh_change_percent' => null,
                ];
            });

        return $this->fillMissingChanges($rows)->take(-$days)->values()->all();
    }

    private function fillMissingChanges(Collection $rows): Collection
    {
        return $rows->values()->map(function (array $row, int $index) use ($rows): array {
            $previous = $rows->get($index - 1);

            if (! is_array($previous)) {
                return [
                    ...$row,
                    'pt_change_percent' => $row['pt_change_percent'] ?? 0.0,
                    'pd_change_percent' => $row['pd_change_percent'] ?? 0.0,
                    'rh_change_percent' => $row['rh_change_percent'] ?? 0.0,
                ];
            }

            return [
                ...$row,
                'pt_change_percent' => $row['pt_change_percent'] ?? $this->percentChange((float) $previous['pt_usd_per_oz'], (float) $row['pt_usd_per_oz']),
                'pd_change_percent' => $row['pd_change_percent'] ?? $this->percentChange((float) $previous['pd_usd_per_oz'], (float) $row['pd_usd_per_oz']),
                'rh_change_percent' => $row['rh_change_percent'] ?? $this->percentChange((float) $previous['rh_usd_per_oz'], (float) $row['rh_usd_per_oz']),
            ];
        });
    }

    private function buildSummary(array $latest): array
    {
        return [
            'date' => $latest['date'] ?? null,
            'pt_usd_per_oz' => isset($latest['pt_usd_per_oz']) ? round((float) $latest['pt_usd_per_oz'], 4) : null,
            'pd_usd_per_oz' => isset($latest['pd_usd_per_oz']) ? round((float) $latest['pd_usd_per_oz'], 4) : null,
            'rh_usd_per_oz' => isset($latest['rh_usd_per_oz']) ? round((float) $latest['rh_usd_per_oz'], 4) : null,
            'pt_eur_per_oz' => isset($latest['pt_eur_per_oz']) ? round((float) $latest['pt_eur_per_oz'], 4) : null,
            'pd_eur_per_oz' => isset($latest['pd_eur_per_oz']) ? round((float) $latest['pd_eur_per_oz'], 4) : null,
            'rh_eur_per_oz' => isset($latest['rh_eur_per_oz']) ? round((float) $latest['rh_eur_per_oz'], 4) : null,
            'pt_change_percent' => isset($latest['pt_change_percent']) ? round((float) $latest['pt_change_percent'], 4) : null,
            'pd_change_percent' => isset($latest['pd_change_percent']) ? round((float) $latest['pd_change_percent'], 4) : null,
            'rh_change_percent' => isset($latest['rh_change_percent']) ? round((float) $latest['rh_change_percent'], 4) : null,
            'currency' => $latest['currency'] ?? 'USD',
            'fx_rate' => isset($latest['fx_rate']) ? round((float) $latest['fx_rate'], 6) : 1.0,
        ];
    }

    private function percentChange(float $from, float $to): float
    {
        if ($from == 0.0) {
            return 0.0;
        }

        return round((($to - $from) / $from) * 100, 4);
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
