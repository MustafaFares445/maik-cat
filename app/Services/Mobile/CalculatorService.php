<?php

namespace App\Services\Mobile;

use App\Models\Item;
use App\Models\MetalPrice;

class CalculatorService
{
    public function __construct(private readonly CurrencyConversionService $currencyConversionService) {}

    public function estimate(Item $item, MetalPrice $metalPrice, float $recoveryRate = 0.8, string $currency = 'USD'): array
    {
        $currency = strtoupper(trim($currency)) === 'EUR' ? 'EUR' : 'USD';
        $fxRate = $currency === 'EUR'
            ? $this->currencyConversionService->rateOrOne('USD', 'EUR')
            : 1.0;

        $weightInGrams = ((float) ($item->weight_kg ?? 0)) * 1000;

        $ptGrams = $this->gramsFromPpm($weightInGrams, (float) ($item->pt_ppm ?? 0));
        $pdGrams = $this->gramsFromPpm($weightInGrams, (float) ($item->pd_ppm ?? 0));
        $rhGrams = $this->gramsFromPpm($weightInGrams, (float) ($item->rh_ppm ?? 0));

        $ptValue = $ptGrams * $metalPrice->ptPerGram() * $recoveryRate;
        $pdValue = $pdGrams * $metalPrice->pdPerGram() * $recoveryRate;
        $rhValue = $rhGrams * $metalPrice->rhPerGram() * $recoveryRate;

        $total = $ptValue + $pdValue + $rhValue;

        return [
            'recovery_rate' => round($recoveryRate, 4),
            'currency' => $currency,
            'fx_rate' => round($fxRate, 6),
            'weight_kg' => round((float) ($item->weight_kg ?? 0), 3),
            'breakdown' => [
                'pt' => [
                    'grams' => round($ptGrams, 6),
                    'usd_per_gram' => round($metalPrice->ptPerGram(), 6),
                    'value_usd' => round($ptValue, 2),
                    'eur_per_gram' => $currency === 'EUR' ? round($metalPrice->ptPerGram() * $fxRate, 6) : null,
                    'value_eur' => $currency === 'EUR' ? round($ptValue * $fxRate, 2) : null,
                ],
                'pd' => [
                    'grams' => round($pdGrams, 6),
                    'usd_per_gram' => round($metalPrice->pdPerGram(), 6),
                    'value_usd' => round($pdValue, 2),
                    'eur_per_gram' => $currency === 'EUR' ? round($metalPrice->pdPerGram() * $fxRate, 6) : null,
                    'value_eur' => $currency === 'EUR' ? round($pdValue * $fxRate, 2) : null,
                ],
                'rh' => [
                    'grams' => round($rhGrams, 6),
                    'usd_per_gram' => round($metalPrice->rhPerGram(), 6),
                    'value_usd' => round($rhValue, 2),
                    'eur_per_gram' => $currency === 'EUR' ? round($metalPrice->rhPerGram() * $fxRate, 6) : null,
                    'value_eur' => $currency === 'EUR' ? round($rhValue * $fxRate, 2) : null,
                ],
            ],
            'total_usd' => round($total, 2),
            'total_eur' => $currency === 'EUR' ? round($total * $fxRate, 2) : null,
            'price_reference' => [
                'id' => $metalPrice->id,
                'fetched_at' => optional($metalPrice->fetched_at)->toIso8601String(),
            ],
        ];
    }

    private function gramsFromPpm(float $weightInGrams, float $ppm): float
    {
        return ($ppm / 1000000) * $weightInGrams;
    }
}
