<?php

namespace App\Services\Mobile;

use App\Models\MetalPrice;

class CalculatorService
{
    public function __construct(private readonly CurrencyConversionService $currencyConversionService) {}

    public function estimate(
        float $weight,
        float $ptPpm,
        float $pdPpm,
        float $rhPpm,
        MetalPrice $metalPrice,
        float $recoveryRate = 0.8,
        string $currency = 'USD'
    ): array
    {
        $currency = strtoupper(trim($currency)) === 'EUR' ? 'EUR' : 'USD';
        $fxRate = $this->currencyConversionService->rateOrOne('USD', 'EUR');

        $weightInGrams = max($weight, 0);

        $recoveryRate = min(max($recoveryRate, 0), 1);

        $ptGrams = $this->gramsFromPpm($weightInGrams, max($ptPpm, 0));
        $pdGrams = $this->gramsFromPpm($weightInGrams, max($pdPpm, 0));
        $rhGrams = $this->gramsFromPpm($weightInGrams, max($rhPpm, 0));

        $ptValue = $ptGrams * $metalPrice->ptPerGram() * $recoveryRate;
        $pdValue = $pdGrams * $metalPrice->pdPerGram() * $recoveryRate;
        $rhValue = $rhGrams * $metalPrice->rhPerGram() * $recoveryRate;

        $totalUsd = $ptValue + $pdValue + $rhValue;
        $totalEur = $totalUsd * $fxRate;

        return [
            'currency' => $currency,
            'totalUsd' => round($totalUsd, 2),
            'totalEur' => round($totalEur, 2),
        ];
    }

    private function gramsFromPpm(float $weightInGrams, float $ppm): float
    {
        return ($ppm / 1000000) * $weightInGrams;
    }
}
