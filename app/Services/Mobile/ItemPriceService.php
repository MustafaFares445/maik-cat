<?php

namespace App\Services\Mobile;

use App\Models\Item;
use Throwable;

class ItemPriceService
{
    private const string DEFAULT_CURRENCY = 'USD';

    private const float DEFAULT_PAYOUT_RATE = 0.65;

    private const float DEFAULT_LEGACY_ECOTRADE_PAYOUT_RATE = 0.1577780772;

    private const float OVERSIZED_WEIGHT_THRESHOLD_KG = 50.0;

    private const float GRAMS_PER_KILOGRAM = 1000.0;

    /** @var array<string, array{platinum: float, palladium: float, rhodium: float}> */
    private array $priceCache = [];

    public function __construct(private readonly MetalsSpotService $metalsSpotService) {}

    public function priceFor(Item $item, ?string $currency = null): float
    {
        $currency = $this->normalizeCurrency($currency);
        $prices = $this->metalPrices($currency);

        $rawWeightKg = max((float) ($item->weight_kg ?? 0), 0.0);
        $weightKg = $this->normalizedWeightKg($rawWeightKg);
        $ptPpm = max((float) ($item->pt_ppm ?? 0), 0.0);
        $pdPpm = max((float) ($item->pd_ppm ?? 0), 0.0);
        $rhPpm = max((float) ($item->rh_ppm ?? 0), 0.0);

        if ($weightKg <= 0.0 || ($ptPpm <= 0.0 && $pdPpm <= 0.0 && $rhPpm <= 0.0)) {
            return 0.0;
        }

        $metalValue = ($weightKg / 1000) * (
            ($ptPpm * $prices['platinum']) +
            ($pdPpm * $prices['palladium']) +
            ($rhPpm * $prices['rhodium'])
        );

        $price = $metalValue * $this->payoutRate($item, $rawWeightKg);
        $roundedPrice = round(max($price, 0.0), 2);

        return $roundedPrice;
    }

    /**
     * @return array{platinum: float, palladium: float, rhodium: float}
     */
    private function metalPrices(string $currency): array
    {
        if (array_key_exists($currency, $this->priceCache)) {
            return $this->priceCache[$currency];
        }

        try {
            $spot = $this->metalsSpotService->all($currency);
        } catch (Throwable $e) {
            return $this->priceCache[$currency] = [
                'platinum' => 0.0,
                'palladium' => 0.0,
                'rhodium' => 0.0,
            ];
        }

        $prices = [
            'platinum' => 0.0,
            'palladium' => 0.0,
            'rhodium' => 0.0,
        ];

        foreach ((array) ($spot['data'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = (string) ($row['key'] ?? '');

            if (! array_key_exists($key, $prices)) {
                continue;
            }

            $priceGram = $this->extractPriceGram($row);

            if ($priceGram !== null) {
                $prices[$key] = $priceGram;
            }
        }

        return $this->priceCache[$currency] = $prices;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function extractPriceGram(array $row): ?float
    {
        if (is_numeric($row['price_gram'] ?? null)) {
            return max((float) $row['price_gram'], 0.0);
        }

        if (is_numeric($row['price_oz'] ?? null)) {
            return max((float) $row['price_oz'] / 31.1035, 0.0);
        }

        return null;
    }

    private function normalizedWeightKg(float $rawWeightKg): float
    {
        if ($rawWeightKg > self::OVERSIZED_WEIGHT_THRESHOLD_KG) {
            return $rawWeightKg / self::GRAMS_PER_KILOGRAM;
        }

        return $rawWeightKg;
    }

    private function payoutRate(Item $item, float $rawWeightKg): float
    {
        if ($this->usesLegacyEcotradeWeight($item, $rawWeightKg)) {
            return $this->boundedRate(
                config('services.item_pricing.legacy_ecotrade_payout_rate', self::DEFAULT_LEGACY_ECOTRADE_PAYOUT_RATE)
            );
        }

        return $this->boundedRate(config('services.item_pricing.payout_rate', self::DEFAULT_PAYOUT_RATE));
    }

    private function usesLegacyEcotradeWeight(Item $item, float $rawWeightKg): bool
    {
        if ($rawWeightKg <= self::OVERSIZED_WEIGHT_THRESHOLD_KG) {
            return false;
        }

        if ((string) $item->source === 'ecotrade') {
            return true;
        }

        return str_contains(strtolower((string) $item->source_url), 'ecotradegroup.com');
    }

    private function boundedRate(mixed $rate): float
    {
        $rate = (float) $rate;

        return min(max($rate, 0.0), 1.0);
    }

    private function normalizeCurrency(?string $currency): string
    {
        $currency = strtoupper(trim((string) ($currency ?? self::DEFAULT_CURRENCY)));

        return $currency === 'EUR' ? 'EUR' : 'USD';
    }
}
