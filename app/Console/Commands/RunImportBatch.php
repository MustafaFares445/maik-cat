<?php

namespace App\Console\Commands;

use App\Services\ImportBatchService;
use Illuminate\Console\Command;
use Throwable;

class RunImportBatch extends Command
{
    protected $signature = 'imports:run
        {path : Absolute path to an .xls/.xlsx file}
        {--dry-run : Parse and profile only without writing items}
        {--imported-by= : Optional importer identity/email}';

    protected $description = 'Queue or preview an Excel import batch';

    public function handle(ImportBatchService $importBatchService): int
    {
        $path = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');
        $importedBy = $this->option('imported-by');
        $importedBy = is_string($importedBy) && trim($importedBy) !== '' ? trim($importedBy) : null;

        try {
            $report = $importBatchService->importFromPath($path, $importedBy, $dryRun);
        } catch (Throwable $exception) {
            $this->error('Import failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Import batch created successfully.');
        $this->line('Batch ID: '.($report['batch_id'] ?? 'n/a'));
        $this->line('Status: '.($report['status'] ?? 'unknown'));
        $this->line('Rows inserted: '.(int) ($report['rows_inserted'] ?? 0));
        $this->line('Rows skipped: '.(int) ($report['rows_skipped'] ?? 0));
        $this->line('Rows flagged: '.(int) ($report['rows_flagged'] ?? 0));
        $this->line('Rows invalid: '.(int) ($report['rows_invalid'] ?? 0));
        $this->line('Duplicates total: '.(int) ($report['duplicates_total'] ?? 0));
        $this->line('Duplicates pending: '.(int) ($report['duplicates_pending'] ?? 0));

        return self::SUCCESS;
    }
}
