<?php

namespace App\Services\Mobile;

use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Services\Pricing\FilterPriceCorrectionService;
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
        private readonly FilterPriceCorrectionService $filterPriceCorrectionService,
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
            $settings['metal_deductions_enabled'],
            $settings['filter_correction_mode'],
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
            $settings['metal_deductions_enabled'],
            $settings['filter_correction_mode'],
        );
    }

    public function priceForFilterMode(Item $item, string $filterCorrectionMode, ?string $currency = null): float
    {
        $settings = $this->itemPriceSettingsService->pricingConfiguration();

        return $this->priceForConfiguration(
            $item,
            $settings['rate_percent'],
            $settings['platinum_deduction_percent'],
            $settings['palladium_deduction_percent'],
            $settings['rhodium_deduction_percent'],
            $currency,
            $settings['metal_deductions_enabled'],
            $filterCorrectionMode,
        );
    }

    public function priceForConfiguration(
        Item $item,
        float $ratePercent,
        float $platinumDeductionPercent,
        float $palladiumDeductionPercent,
        float $rhodiumDeductionPercent,
        ?string $currency = null,
        bool $metalDeductionsEnabled = false,
        string $filterCorrectionMode = FilterPriceCorrectionService::MODE_DISABLED,
    ): float {
        $assay = $this->filterPriceCorrectionService->effectiveAssay($item, $filterCorrectionMode);

        return $this->priceForAssay(
            $assay,
            $ratePercent,
            $platinumDeductionPercent,
            $palladiumDeductionPercent,
            $rhodiumDeductionPercent,
            $currency,
            $metalDeductionsEnabled,
        );
    }

    public function priceForMappingPreview(
        Item $item,
        ItemFilterMapping $mapping,
        string $filterCorrectionMode,
        ?string $currency = null,
    ): float {
        $settings = $this->itemPriceSettingsService->pricingConfiguration();
        $assay = $this->filterPriceCorrectionService->effectiveAssayForMapping($item, $mapping, $filterCorrectionMode);

        return $this->priceForAssay(
            $assay,
            $settings['rate_percent'],
            $settings['platinum_deduction_percent'],
            $settings['palladium_deduction_percent'],
            $settings['rhodium_deduction_percent'],
            $currency,
            $settings['metal_deductions_enabled'],
        );
    }

    /** @param array{weight_kg:float,pt_ppm:float,pd_ppm:float,rh_ppm:float} $assay */
    private function priceForAssay(
        array $assay,
        float $ratePercent,
        float $platinumDeductionPercent,
        float $palladiumDeductionPercent,
        float $rhodiumDeductionPercent,
        ?string $currency,
        bool $metalDeductionsEnabled,
    ): float {
        $currency = $this->normalizeCurrency($currency);
        $prices = $this->metalPrices($currency);
        $weightKg = max((float) $assay['weight_kg'], 0.0);
        $ptPpm = max((float) $assay['pt_ppm'], 0.0);
        $pdPpm = max((float) $assay['pd_ppm'], 0.0);
        $rhPpm = max((float) $assay['rh_ppm'], 0.0);

        if ($weightKg <= 0.0 || ($ptPpm <= 0.0 && $pdPpm <= 0.0 && $rhPpm <= 0.0)) {
            return 0.0;
        }

        $ptFactor = $metalDeductionsEnabled ? $this->remainingFactor($platinumDeductionPercent) : 1.0;
        $pdFactor = $metalDeductionsEnabled ? $this->remainingFactor($palladiumDeductionPercent) : 1.0;
        $rhFactor = $metalDeductionsEnabled ? $this->remainingFactor($rhodiumDeductionPercent) : 1.0;

        $metalValue = ($weightKg / self::GRAMS_PER_KILOGRAM) * (
            ($ptPpm * $prices['platinum'] * $ptFactor) +
            ($pdPpm * $prices['palladium'] * $pdFactor) +
            ($rhPpm * $prices['rhodium'] * $rhFactor)
        );

        return round(max($metalValue * $this->normalizePercent($ratePercent), 0.0), 2);
    }

    /** @return array{platinum: float, palladium: float, rhodium: float} */
    private function metalPrices(string $currency): array
    {
        if (array_key_exists($currency, $this->priceCache)) {
            return $this->priceCache[$currency];
        }

        try {
            $spot = $this->metalsSpotService->all($currency);
        } catch (Throwable) {
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

    /** @param array<string,mixed> $row */
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
