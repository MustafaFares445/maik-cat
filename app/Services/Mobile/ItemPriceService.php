<?php

namespace App\Services\Mobile;

use App\Models\Item;
use Throwable;

class ItemPriceService
{
    private const string DEFAULT_CURRENCY = 'USD';

    private const float EXCEL_TROY_OUNCE_GRAMS = 31.1043;

    private const float GRAMS_PER_KILOGRAM = 1000.0;

    /** @var array<string, array{platinum: float, palladium: float, rhodium: float}> */
    private array $priceCache = [];

    public function __construct(
        private readonly MetalsSpotService $metalsSpotService,
        private readonly ItemPriceSettingsService $itemPriceSettingsService,
    ) {}

    public function priceFor(Item $item, ?string $currency = null): float
    {
        $settings = $this->itemPriceSettingsService->pricingConfiguration();

        return $this->priceForConfiguration(
            $item,
            $settings['rate_percent'],
            $settings['platinum_deduction_percent'],
            $settings['palladium_deduction_percent'],
            $settings['rhodium_deduction_percent'],
            $currency,
        );
    }

    public function priceForRate(Item $item, float $ratePercent, ?string $currency = null): float
    {
        $settings = $this->itemPriceSettingsService->pricingConfiguration();

        return $this->priceForConfiguration(
            $item,
            $ratePercent,
            $settings['platinum_deduction_percent'],
            $settings['palladium_deduction_percent'],
            $settings['rhodium_deduction_percent'],
            $currency,
        );
    }

    public function priceForConfiguration(
        Item $item,
        float $ratePercent,
        float $platinumDeductionPercent,
        float $palladiumDeductionPercent,
        float $rhodiumDeductionPercent,
        ?string $currency = null,
    ): float {
        $currency = $this->normalizeCurrency($currency);
        $prices = $this->metalPrices($currency);

        $weightKg = max((float) ($item->weight_kg ?? 0), 0.0);
        $ptPpm = max((float) ($item->pt_ppm ?? 0), 0.0);
        $pdPpm = max((float) ($item->pd_ppm ?? 0), 0.0);
        $rhPpm = max((float) ($item->rh_ppm ?? 0), 0.0);

        if ($weightKg <= 0.0 || ($ptPpm <= 0.0 && $pdPpm <= 0.0 && $rhPpm <= 0.0)) {
            return 0.0;
        }

        $metalValue = ($weightKg / self::GRAMS_PER_KILOGRAM) * (
            ($ptPpm * $prices['platinum'] * $this->remainingFactor($platinumDeductionPercent)) +
            ($pdPpm * $prices['palladium'] * $this->remainingFactor($palladiumDeductionPercent)) +
            ($rhPpm * $prices['rhodium'] * $this->remainingFactor($rhodiumDeductionPercent))
        );

        $price = $metalValue * $this->normalizePercent($ratePercent);

        return round(max($price, 0.0), 2);
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
        if (is_numeric($row['price_oz'] ?? null)) {
            return max((float) $row['price_oz'] / self::EXCEL_TROY_OUNCE_GRAMS, 0.0);
        }

        if (is_numeric($row['price_gram'] ?? null)) {
            return max((float) $row['price_gram'], 0.0);
        }

        return null;
    }

    private function remainingFactor(float $deductionPercent): float
    {
        return 1 - $this->normalizePercent($deductionPercent);
    }

    private function normalizePercent(float $percent): float
    {
        return min(max($percent, 0.0), 100.0) / 100;
    }

    private function normalizeCurrency(?string $currency): string
    {
        $currency = strtoupper(trim((string) ($currency ?? self::DEFAULT_CURRENCY)));

        return $currency === 'EUR' ? 'EUR' : 'USD';
    }
}
