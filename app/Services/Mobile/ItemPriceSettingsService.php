<?php

namespace App\Services\Mobile;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

class ItemPriceSettingsService
{
    public const string RATE_PERCENT_KEY = 'item_price_rate_percent';

    public const float DEFAULT_RATE_PERCENT = 80.0;

    private ?float $ratePercent = null;

    public function ratePercent(): float
    {
        if ($this->ratePercent !== null) {
            return $this->ratePercent;
        }

        if (! Schema::hasTable('settings')) {
            return $this->ratePercent = self::DEFAULT_RATE_PERCENT;
        }

        $storedValue = Setting::query()
            ->whereKey(self::RATE_PERCENT_KEY)
            ->value('value');

        return $this->ratePercent = $this->normalizeRatePercent(
            $storedValue ?? self::DEFAULT_RATE_PERCENT,
        );
    }

    public function updateRatePercent(float $ratePercent): float
    {
        $ratePercent = $this->normalizeRatePercent($ratePercent);

        Setting::query()->updateOrCreate(
            ['key' => self::RATE_PERCENT_KEY],
            ['value' => (string) $ratePercent],
        );

        return $this->ratePercent = $ratePercent;
    }

    private function normalizeRatePercent(mixed $ratePercent): float
    {
        return min(max((float) $ratePercent, 0.0), 100.0);
    }
}
