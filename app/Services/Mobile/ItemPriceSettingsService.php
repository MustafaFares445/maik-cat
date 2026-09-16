<?php

namespace App\Services\Mobile;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

class ItemPriceSettingsService
{
    public const string RATE_PERCENT_KEY = 'item_price_rate_percent';

    public const string PLATINUM_DEDUCTION_PERCENT_KEY = 'item_price_platinum_deduction_percent';

    public const string PALLADIUM_DEDUCTION_PERCENT_KEY = 'item_price_palladium_deduction_percent';

    public const string RHODIUM_DEDUCTION_PERCENT_KEY = 'item_price_rhodium_deduction_percent';

    public const float DEFAULT_RATE_PERCENT = 80.0;

    public const float DEFAULT_PLATINUM_DEDUCTION_PERCENT = 2.0;

    public const float DEFAULT_PALLADIUM_DEDUCTION_PERCENT = 2.0;

    public const float DEFAULT_RHODIUM_DEDUCTION_PERCENT = 10.0;

    /** @var array<string, float> */
    private array $cache = [];

    public function ratePercent(): float
    {
        return $this->percent(self::RATE_PERCENT_KEY, self::DEFAULT_RATE_PERCENT);
    }

    public function platinumDeductionPercent(): float
    {
        return $this->percent(
            self::PLATINUM_DEDUCTION_PERCENT_KEY,
            self::DEFAULT_PLATINUM_DEDUCTION_PERCENT,
        );
    }

    public function palladiumDeductionPercent(): float
    {
        return $this->percent(
            self::PALLADIUM_DEDUCTION_PERCENT_KEY,
            self::DEFAULT_PALLADIUM_DEDUCTION_PERCENT,
        );
    }

    public function rhodiumDeductionPercent(): float
    {
        return $this->percent(
            self::RHODIUM_DEDUCTION_PERCENT_KEY,
            self::DEFAULT_RHODIUM_DEDUCTION_PERCENT,
        );
    }

    /**
     * @return array{
     *     rate_percent: float,
     *     platinum_deduction_percent: float,
     *     palladium_deduction_percent: float,
     *     rhodium_deduction_percent: float
     * }
     */
    public function pricingConfiguration(): array
    {
        return [
            'rate_percent' => $this->ratePercent(),
            'platinum_deduction_percent' => $this->platinumDeductionPercent(),
            'palladium_deduction_percent' => $this->palladiumDeductionPercent(),
            'rhodium_deduction_percent' => $this->rhodiumDeductionPercent(),
        ];
    }

    public function updateRatePercent(float $ratePercent): float
    {
        return $this->writePercent(self::RATE_PERCENT_KEY, $ratePercent);
    }

    /**
     * @return array{
     *     rate_percent: float,
     *     platinum_deduction_percent: float,
     *     palladium_deduction_percent: float,
     *     rhodium_deduction_percent: float
     * }
     */
    public function updatePricingConfiguration(
        float $ratePercent,
        float $platinumDeductionPercent,
        float $palladiumDeductionPercent,
        float $rhodiumDeductionPercent,
    ): array {
        $this->writePercent(self::RATE_PERCENT_KEY, $ratePercent);
        $this->writePercent(self::PLATINUM_DEDUCTION_PERCENT_KEY, $platinumDeductionPercent);
        $this->writePercent(self::PALLADIUM_DEDUCTION_PERCENT_KEY, $palladiumDeductionPercent);
        $this->writePercent(self::RHODIUM_DEDUCTION_PERCENT_KEY, $rhodiumDeductionPercent);

        return $this->pricingConfiguration();
    }

    private function percent(string $key, float $default): float
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        if (! Schema::hasTable('settings')) {
            return $this->cache[$key] = $default;
        }

        $storedValue = Setting::query()
            ->whereKey($key)
            ->value('value');

        return $this->cache[$key] = $this->normalizePercent($storedValue ?? $default);
    }

    private function writePercent(string $key, float $value): float
    {
        $value = $this->normalizePercent($value);

        if (Schema::hasTable('settings')) {
            Setting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => (string) $value],
            );
        }

        return $this->cache[$key] = $value;
    }

    private function normalizePercent(mixed $value): float
    {
        return min(max((float) $value, 0.0), 100.0);
    }
}
