<?php

namespace App\Services\Mobile;

use Illuminate\Support\Collection;
use RuntimeException;

class ThirdPartyMarketService
{
    public function __construct(
        private readonly MetalsSpotService $metalsSpotService,
        private readonly CurrencyConversionService $currencyConversionService,
    ) {}

    public function changes(int $days = 14, string $currency = 'USD'): array
    {
        $days = max(1, min($days, 14));
        $currencyUpper = strtoupper(trim($currency));

        $spotUsd = $this->metalsSpotService->all('USD');
        $tripletUsd = $this->tripletFromSpotRows($spotUsd['data']);

        $fxRate = 1.0;
        $tripletDisplay = $tripletUsd;

        if ($currencyUpper === 'EUR') {
            $spotEur = $this->metalsSpotService->all('EUR');
            $tripletDisplay = $this->tripletFromSpotRows($spotEur['data']);
            $fxRate = $this->currencyConversionService->rateOrOne('USD', 'EUR');
        }

        $rows = collect(range($days - 1, 0))
            ->map(function (int $offset) use ($tripletUsd, $tripletDisplay, $currencyUpper, $fxRate): array {
                $date = now()->subDays($offset)->toDateString();
                $isToday = $offset === 0;

                $row = [
                    'date' => $date,
                    'pt_usd_per_oz' => $tripletUsd['pt']['price_oz'],
                    'pd_usd_per_oz' => $tripletUsd['pd']['price_oz'],
                    'rh_usd_per_oz' => $tripletUsd['rh']['price_oz'],
                    'pt_change_percent' => $isToday ? $tripletUsd['pt']['change_pct'] : null,
                    'pd_change_percent' => $isToday ? $tripletUsd['pd']['change_pct'] : null,
                    'rh_change_percent' => $isToday ? $tripletUsd['rh']['change_pct'] : null,
                ];

                if ($currencyUpper === 'EUR') {
                    $row['pt_eur_per_oz'] = $tripletDisplay['pt']['price_oz'];
                    $row['pd_eur_per_oz'] = $tripletDisplay['pd']['price_oz'];
                    $row['rh_eur_per_oz'] = $tripletDisplay['rh']['price_oz'];
                    $row['currency'] = 'EUR';
                    $row['fx_rate'] = round($fxRate, 6);
                }

                return $row;
            })
            ->sortBy('date')
            ->values();

        return $this->fillMissingChanges($rows)->all();
    }

    public function homepageStats(string $currency = 'USD'): array
    {
        $normalizedCurrency = strtoupper(trim($currency)) === 'EUR' ? 'EUR' : 'USD';
        $spot = $this->metalsSpotService->all($normalizedCurrency);
        $triplet = $this->tripletFromSpotRows($spot['data']);
        $byKey = collect($spot['data'])->keyBy(fn (array $row): string => (string) ($row['key'] ?? ''));

        $ptRow = (array) $byKey->get('platinum', []);
        $pdRow = (array) $byKey->get('palladium', []);
        $rhRow = (array) $byKey->get('rhodium', []);

        return [
            'source' => $spot['source'],
            'currency' => $normalizedCurrency,
            'changes' => [
                'pt_change_percent' => round((float) ($ptRow['change_pct'] ?? 0.0), 4),
                'pd_change_percent' => round((float) ($pdRow['change_pct'] ?? 0.0), 4),
                'rh_change_percent' => round((float) ($rhRow['change_pct'] ?? 0.0), 4),
            ],
            'summary' => [
                'pt_bid_per_oz' => $triplet['pt']['price_oz'],
                'pt_price_per_gram' => round((float) ($ptRow['price_gram'] ?? 0.0), 4),
                'pd_bid_per_oz' => $triplet['pd']['price_oz'],
                'pd_price_per_gram' => round((float) ($pdRow['price_gram'] ?? 0.0), 4),
                'rh_bid_per_oz' => $triplet['rh']['price_oz'],
                'rh_price_per_gram' => round((float) ($rhRow['price_gram'] ?? 0.0), 4),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{pt: array{price_oz: float, change_pct: float}, pd: array{price_oz: float, change_pct: float}, rh: array{price_oz: float, change_pct: float}}
     */
    private function tripletFromSpotRows(array $rows): array
    {
        $byKey = collect($rows)->keyBy(fn (array $row): string => (string) ($row['key'] ?? ''));

        $pt = $byKey->get('platinum');
        $pd = $byKey->get('palladium');
        $rh = $byKey->get('rhodium');

        if (! is_array($pt) || ! is_array($pd) || ! is_array($rh)) {
            throw new RuntimeException(MetalsSpotService::UPSTREAM_UNAVAILABLE_MESSAGE);
        }

        foreach ([$pt, $pd, $rh] as $metal) {
            $price = (float) ($metal['price_oz'] ?? 0.0);

            if ($price <= 0.0) {
                throw new RuntimeException(MetalsSpotService::UPSTREAM_UNAVAILABLE_MESSAGE);
            }
        }

        return [
            'pt' => [
                'price_oz' => round((float) $pt['price_oz'], 4),
                'change_pct' => round((float) ($pt['change_pct'] ?? 0.0), 4),
            ],
            'pd' => [
                'price_oz' => round((float) $pd['price_oz'], 4),
                'change_pct' => round((float) ($pd['change_pct'] ?? 0.0), 4),
            ],
            'rh' => [
                'price_oz' => round((float) $rh['price_oz'], 4),
                'change_pct' => round((float) ($rh['change_pct'] ?? 0.0), 4),
            ],
        ];
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
}
