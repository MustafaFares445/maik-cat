<?php

namespace App\Jobs;

use App\Services\ImportBatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ImportBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $batchId,
        public readonly string $storedFilePath,
    ) {}

    public function handle(ImportBatchService $importBatchService): void
    {
        $importBatchService->processQueuedBatch($this->batchId, $this->storedFilePath);
    }
}
