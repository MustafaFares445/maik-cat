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
        string $weightUnit = 'g',
        ?float $ptUsdPerGram = null,
        ?float $pdUsdPerGram = null,
        ?float $rhUsdPerGram = null,
        float $ptRate = 0.0,
        float $pdRate = 0.0,
        float $rhRate = 0.0,
        float $humidityRate = 0.0,
        string $currency = 'USD'
    ): array
    {
        $currency = strtoupper(trim($currency)) === 'EUR' ? 'EUR' : 'USD';
        $fxRate = $this->currencyConversionService->rateOrOne('USD', 'EUR');

        $weightUnit = strtolower(trim($weightUnit)) === 'kg' ? 'kg' : 'g';
        $weightInput = max($weight, 0);
        $weightInGrams = $weightUnit === 'kg' ? ($weightInput * 1000) : $weightInput;

        $humidityRate = min(max($humidityRate, 0), 1);
        $dryFactor = 1 - $humidityRate;

        $ptGrams = $this->gramsFromPpm($weightInGrams, max($ptPpm, 0));
        $pdGrams = $this->gramsFromPpm($weightInGrams, max($pdPpm, 0));
        $rhGrams = $this->gramsFromPpm($weightInGrams, max($rhPpm, 0));

        $ptUsdPerGram = max($ptUsdPerGram ?? $metalPrice->ptPerGram(), 0);
        $pdUsdPerGram = max($pdUsdPerGram ?? $metalPrice->pdPerGram(), 0);
        $rhUsdPerGram = max($rhUsdPerGram ?? $metalPrice->rhPerGram(), 0);

        $ptRate = min(max($ptRate, 0), 1);
        $pdRate = min(max($pdRate, 0), 1);
        $rhRate = min(max($rhRate, 0), 1);

        $ptValue = $ptGrams * $ptUsdPerGram * $ptRate * $dryFactor;
        $pdValue = $pdGrams * $pdUsdPerGram * $pdRate * $dryFactor;
        $rhValue = $rhGrams * $rhUsdPerGram * $rhRate * $dryFactor;

        $totalUsd = $ptValue + $pdValue + $rhValue;
        $totalEur = $totalUsd * $fxRate;

        return [
            'currency' => $currency,
            'inputs' => [
                'weight' => round($weightInput, 3),
                'weightUnit' => $weightUnit,
                'weightInGrams' => round($weightInGrams, 2),
                'ptPpm' => round(max($ptPpm, 0), 2),
                'pdPpm' => round(max($pdPpm, 0), 2),
                'rhPpm' => round(max($rhPpm, 0), 2),
                'ptRate' => round($ptRate, 4),
                'pdRate' => round($pdRate, 4),
                'rhRate' => round($rhRate, 4),
                'humidityRate' => round($humidityRate, 4),
            ],
            'breakdown' => [
                'pt' => [
                    'grams' => round($ptGrams, 6),
                    'usdPerGram' => round($ptUsdPerGram, 2),
                    'valueUsd' => round($ptValue, 2),
                    'valueEur' => round($ptValue * $fxRate, 2),
                ],
                'pd' => [
                    'grams' => round($pdGrams, 6),
                    'usdPerGram' => round($pdUsdPerGram, 2),
                    'valueUsd' => round($pdValue, 2),
                    'valueEur' => round($pdValue * $fxRate, 2),
                ],
                'rh' => [
                    'grams' => round($rhGrams, 6),
                    'usdPerGram' => round($rhUsdPerGram, 2),
                    'valueUsd' => round($rhValue, 2),
                    'valueEur' => round($rhValue * $fxRate, 2),
                ],
            ],
            'totalUsd' => round($totalUsd, 2),
            'totalEur' => round($totalEur, 2),
        ];
    }

    private function gramsFromPpm(float $weightInGrams, float $ppm): float
    {
        return ($ppm / 1000000) * $weightInGrams;
    }
}
