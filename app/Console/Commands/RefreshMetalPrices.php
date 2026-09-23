<?php

namespace App\Console\Commands;

use App\Models\MetalPrice;
use App\Services\Mobile\MetalsSpotService;
use Illuminate\Console\Command;

class RefreshMetalPrices extends Command
{
    protected $signature = 'metals:refresh';

    protected $description = 'Refresh metal spot prices and store a historical snapshot';

    public function handle(MetalsSpotService $service): int
    {
        $this->info('Fetching fresh metal prices...');

        try {
            $result = $service->refresh();

            if (($result['stale'] ?? false) === true) {
                $this->warn('Upstream is unavailable. Using cached fallback and skipping historical snapshot storage.');
            } else {
                $this->storeSnapshot($result);
            }

            $this->info("Done. Source: {$result['source']} - {$result['updated_at']}");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error("Failed: {$exception->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function storeSnapshot(array $result): void
    {
        $rows = collect($result['data'] ?? [])->keyBy('key');

        $platinum = $rows->get('platinum');
        $palladium = $rows->get('palladium');
        $rhodium = $rows->get('rhodium');

        foreach ([$platinum, $palladium, $rhodium] as $metal) {
            if (! is_array($metal) || ! is_numeric($metal['price_oz'] ?? null) || (float) $metal['price_oz'] <= 0) {
                throw new \RuntimeException('Metal price snapshot is incomplete and was not stored.');
            }
        }

        MetalPrice::query()->create([
            'pt_usd_per_oz' => (float) $platinum['price_oz'],
            'pd_usd_per_oz' => (float) $palladium['price_oz'],
            'rh_usd_per_oz' => (float) $rhodium['price_oz'],
            'source' => (string) ($result['source'] ?? 'metal-sentinel'),
            'fetched_at' => now(),
        ]);

        $this->info('Historical metal price snapshot stored.');
    }
}
