<?php

namespace App\Console\Commands;

use App\Services\Mobile\MetalsSpotService;
use Illuminate\Console\Command;

class RefreshMetalPrices extends Command
{
    protected $signature = 'metals:refresh';

    protected $description = 'Pre-warm the metals spot price cache';

    public function handle(MetalsSpotService $service): int
    {
        $this->info('Fetching fresh metal prices...');

        try {
            $result = $service->refresh();

            $this->info("Done. Source: {$result['source']} - {$result['updated_at']}");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error("Failed: {$exception->getMessage()}");

            return self::FAILURE;
        }
    }
}
