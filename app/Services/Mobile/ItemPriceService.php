<?php

namespace App\Services\Mobile;

use App\Models\Item;
use Illuminate\Support\Facades\Log;
use Throwable;

class ItemPriceService
{
    private const string DEFAULT_CURRENCY = 'USD';

    /** @var array<string, array{platinum: float, palladium: float, rhodium: float}> */
    private array $priceCache = [];

    public function __construct(private readonly MetalsSpotService $metalsSpotService) {}

    public function priceFor(Item $item, ?string $currency = null): float
    {
        $currency = $this->normalizeCurrency($currency);
        $prices = $this->metalPrices($currency);

        $rawWeightKg = max((float) ($item->weight_kg ?? 0), 0.0);
        $weightKg = $rawWeightKg > 50.0 ? ($rawWeightKg / 1000.0) : $rawWeightKg;
        $ptPpm = max((float) ($item->pt_ppm ?? 0), 0.0);
        $pdPpm = max((float) ($item->pd_ppm ?? 0), 0.0);
        $rhPpm = max((float) ($item->rh_ppm ?? 0), 0.0);

        // #region agent log
        $this->debugLog('initial', 'A,B,D', 'app/Services/Mobile/ItemPriceService.php:31', 'Item price inputs resolved', [
            'item_id' => $item->getKey(),
            'currency' => $currency,
            'weight_kg' => $weightKg,
            'raw_weight_kg' => $rawWeightKg,
            'pt_ppm' => $ptPpm,
            'pd_ppm' => $pdPpm,
            'rh_ppm' => $rhPpm,
            'prices' => $prices,
        ]);
        // #endregion

        if ($weightKg <= 0.0 || ($ptPpm <= 0.0 && $pdPpm <= 0.0 && $rhPpm <= 0.0)) {
            // #region agent log
            $this->debugLog('initial', 'A,D', 'app/Services/Mobile/ItemPriceService.php:43', 'Item price returned zero because item values are not positive', [
                'item_id' => $item->getKey(),
                'weight_kg' => $weightKg,
                'raw_weight_kg' => $rawWeightKg,
                'pt_ppm' => $ptPpm,
                'pd_ppm' => $pdPpm,
                'rh_ppm' => $rhPpm,
            ]);
            // #endregion

            return 0.0;
        }

        $price = ($weightKg / 1000) * (
            ($ptPpm * $prices['platinum']) +
            ($pdPpm * $prices['palladium']) +
            ($rhPpm * $prices['rhodium'])
        );

        $roundedPrice = round(max($price, 0.0), 2);

        // #region agent log
        $this->debugLog('initial', 'B,D', 'app/Services/Mobile/ItemPriceService.php:61', 'Item price calculated', [
            'item_id' => $item->getKey(),
            'raw_price' => $price,
            'rounded_price' => $roundedPrice,
            'weight_kg' => $weightKg,
            'raw_weight_kg' => $rawWeightKg,
            'prices' => $prices,
        ]);
        // #endregion

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
            // #region agent log
            $this->debugLog('initial', 'B', 'app/Services/Mobile/ItemPriceService.php:82', 'Metal spot lookup failed inside ItemPriceService', [
                'currency' => $currency,
                'exception' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
            // #endregion

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

        // #region agent log
        $this->debugLog('initial', 'B', 'app/Services/Mobile/ItemPriceService.php:120', 'Metal prices extracted for item pricing', [
            'currency' => $currency,
            'source' => $spot['source'] ?? null,
            'spot_count' => is_countable($spot['data'] ?? null) ? count($spot['data']) : null,
            'prices' => $prices,
        ]);
        // #endregion

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

    private function normalizeCurrency(?string $currency): string
    {
        $currency = strtoupper(trim((string) ($currency ?? self::DEFAULT_CURRENCY)));

        return $currency === 'EUR' ? 'EUR' : 'USD';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function debugLog(string $runId, string $hypothesisId, string $location, string $message, array $data): void
    {
        try {
            file_put_contents(base_path('debug-f25a9f.log'), json_encode([
                'sessionId' => 'f25a9f',
                'runId' => $runId,
                'hypothesisId' => $hypothesisId,
                'location' => $location,
                'message' => $message,
                'data' => $data,
                'timestamp' => (int) round(microtime(true) * 1000),
            ], JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
        } catch (Throwable $e) {
            Log::debug('Agent debug log write failed', ['exception' => $e::class]);
        }
    }
}
