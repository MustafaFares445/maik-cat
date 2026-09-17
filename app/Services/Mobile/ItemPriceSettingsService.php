<?php

namespace App\Services\Mobile;

use App\Models\Setting;
use App\Services\Pricing\FilterPriceCorrectionService;
use Illuminate\Support\Facades\Schema;

class ItemPriceSettingsService
{
    public const string RATE_PERCENT_KEY = 'item_price_rate_percent';
    public const string METAL_DEDUCTIONS_ENABLED_KEY = 'item_price_metal_deductions_enabled';
    public const string PLATINUM_DEDUCTION_PERCENT_KEY = 'item_price_platinum_deduction_percent';
    public const string PALLADIUM_DEDUCTION_PERCENT_KEY = 'item_price_palladium_deduction_percent';
    public const string RHODIUM_DEDUCTION_PERCENT_KEY = 'item_price_rhodium_deduction_percent';
    public const string FILTER_CORRECTION_MODE_KEY = 'item_price_filter_correction_mode';
    public const string FILTER_CANDIDATE_PRICE_THRESHOLD_KEY = 'item_price_filter_candidate_price_threshold';
    public const string FILTER_CANDIDATE_WEIGHT_THRESHOLD_KEY = 'item_price_filter_candidate_weight_threshold_kg';

    public const float DEFAULT_RATE_PERCENT = 80.0;
    public const bool DEFAULT_METAL_DEDUCTIONS_ENABLED = false;
    public const float DEFAULT_PLATINUM_DEDUCTION_PERCENT = 2.0;
    public const float DEFAULT_PALLADIUM_DEDUCTION_PERCENT = 2.0;
    public const float DEFAULT_RHODIUM_DEDUCTION_PERCENT = 10.0;
    public const string DEFAULT_FILTER_CORRECTION_MODE = FilterPriceCorrectionService::MODE_DISABLED;
    public const float DEFAULT_FILTER_CANDIDATE_PRICE_THRESHOLD = 700.0;
    public const float DEFAULT_FILTER_CANDIDATE_WEIGHT_THRESHOLD_KG = 1.5;

    /** @var array<string, mixed> */
    private array $cache = [];

    public function ratePercent(): float
    {
        return $this->percent(self::RATE_PERCENT_KEY, self::DEFAULT_RATE_PERCENT);
    }

    public function metalDeductionsEnabled(): bool
    {
        return $this->boolean(self::METAL_DEDUCTIONS_ENABLED_KEY, self::DEFAULT_METAL_DEDUCTIONS_ENABLED);
    }

    public function platinumDeductionPercent(): float
    {
        return $this->percent(self::PLATINUM_DEDUCTION_PERCENT_KEY, self::DEFAULT_PLATINUM_DEDUCTION_PERCENT);
    }

    public function palladiumDeductionPercent(): float
    {
        return $this->percent(self::PALLADIUM_DEDUCTION_PERCENT_KEY, self::DEFAULT_PALLADIUM_DEDUCTION_PERCENT);
    }

    public function rhodiumDeductionPercent(): float
    {
        return $this->percent(self::RHODIUM_DEDUCTION_PERCENT_KEY, self::DEFAULT_RHODIUM_DEDUCTION_PERCENT);
    }

    public function filterCorrectionMode(): string
    {
        return $this->mode(self::FILTER_CORRECTION_MODE_KEY, self::DEFAULT_FILTER_CORRECTION_MODE);
    }

    public function filterCandidatePriceThreshold(): float
    {
        return $this->nonNegative(self::FILTER_CANDIDATE_PRICE_THRESHOLD_KEY, self::DEFAULT_FILTER_CANDIDATE_PRICE_THRESHOLD);
    }

    public function filterCandidateWeightThresholdKg(): float
    {
        return $this->nonNegative(self::FILTER_CANDIDATE_WEIGHT_THRESHOLD_KEY, self::DEFAULT_FILTER_CANDIDATE_WEIGHT_THRESHOLD_KG);
    }

    /** @return array{rate_percent:float,metal_deductions_enabled:bool,platinum_deduction_percent:float,palladium_deduction_percent:float,rhodium_deduction_percent:float,filter_correction_mode:string,filter_candidate_price_threshold:float,filter_candidate_weight_threshold_kg:float} */
    public function pricingConfiguration(): array
    {
        return [
            'rate_percent' => $this->ratePercent(),
            'metal_deductions_enabled' => $this->metalDeductionsEnabled(),
            'platinum_deduction_percent' => $this->platinumDeductionPercent(),
            'palladium_deduction_percent' => $this->palladiumDeductionPercent(),
            'rhodium_deduction_percent' => $this->rhodiumDeductionPercent(),
            'filter_correction_mode' => $this->filterCorrectionMode(),
            'filter_candidate_price_threshold' => $this->filterCandidatePriceThreshold(),
            'filter_candidate_weight_threshold_kg' => $this->filterCandidateWeightThresholdKg(),
        ];
    }

    public function updateRatePercent(float $ratePercent): float
    {
        return $this->writeNumber(self::RATE_PERCENT_KEY, $this->normalizePercent($ratePercent));
    }

    /** @return array{rate_percent:float,metal_deductions_enabled:bool,platinum_deduction_percent:float,palladium_deduction_percent:float,rhodium_deduction_percent:float,filter_correction_mode:string,filter_candidate_price_threshold:float,filter_candidate_weight_threshold_kg:float} */
    public function updatePricingConfiguration(
        float $ratePercent,
        bool $metalDeductionsEnabled,
        float $platinumDeductionPercent,
        float $palladiumDeductionPercent,
        float $rhodiumDeductionPercent,
        string $filterCorrectionMode,
        float $filterCandidatePriceThreshold,
        float $filterCandidateWeightThresholdKg,
    ): array {
        $this->writeNumber(self::RATE_PERCENT_KEY, $this->normalizePercent($ratePercent));
        $this->writeBoolean(self::METAL_DEDUCTIONS_ENABLED_KEY, $metalDeductionsEnabled);
        $this->writeNumber(self::PLATINUM_DEDUCTION_PERCENT_KEY, $this->normalizePercent($platinumDeductionPercent));
        $this->writeNumber(self::PALLADIUM_DEDUCTION_PERCENT_KEY, $this->normalizePercent($palladiumDeductionPercent));
        $this->writeNumber(self::RHODIUM_DEDUCTION_PERCENT_KEY, $this->normalizePercent($rhodiumDeductionPercent));
        $this->writeString(self::FILTER_CORRECTION_MODE_KEY, $this->normalizeMode($filterCorrectionMode));
        $this->writeNumber(self::FILTER_CANDIDATE_PRICE_THRESHOLD_KEY, max($filterCandidatePriceThreshold, 0.0));
        $this->writeNumber(self::FILTER_CANDIDATE_WEIGHT_THRESHOLD_KEY, max($filterCandidateWeightThresholdKg, 0.0));

        return $this->pricingConfiguration();
    }

    private function stored(string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        if (! Schema::hasTable('settings')) {
            return $this->cache[$key] = $default;
        }

        return Setting::query()->whereKey($key)->value('value') ?? $default;
    }

    private function percent(string $key, float $default): float
    {
        return $this->cache[$key] = $this->normalizePercent($this->stored($key, $default));
    }

    private function nonNegative(string $key, float $default): float
    {
        return $this->cache[$key] = max((float) $this->stored($key, $default), 0.0);
    }

    private function boolean(string $key, bool $default): bool
    {
        $value = $this->stored($key, $default);
        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $this->cache[$key] = $normalized ?? $default;
    }

    private function mode(string $key, string $default): string
    {
        return $this->cache[$key] = $this->normalizeMode((string) $this->stored($key, $default));
    }

    private function writeNumber(string $key, float $value): float
    {
        $this->persist($key, (string) $value);

        return $this->cache[$key] = $value;
    }

    private function writeBoolean(string $key, bool $value): bool
    {
        $this->persist($key, $value ? '1' : '0');

        return $this->cache[$key] = $value;
    }

    private function writeString(string $key, string $value): string
    {
        $this->persist($key, $value);

        return $this->cache[$key] = $value;
    }

    private function persist(string $key, string $value): void
    {
        if (Schema::hasTable('settings')) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    private function normalizePercent(mixed $value): float
    {
        return min(max((float) $value, 0.0), 100.0);
    }

    private function normalizeMode(string $mode): string
    {
        return in_array($mode, [
            FilterPriceCorrectionService::MODE_DISABLED,
            FilterPriceCorrectionService::MODE_WEIGHT_ONLY,
            FilterPriceCorrectionService::MODE_WEIGHT_AND_METALS,
        ], true)
            ? $mode
            : self::DEFAULT_FILTER_CORRECTION_MODE;
    }
}
