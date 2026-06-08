<?php

namespace App\Console\Commands;

use App\Services\ExcelItemEnrichmentService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class EnrichItemsFromExcelCommand extends Command
{
    protected $signature = 'imports:enrich-items
        {paths* : One or more Excel workbook paths}
        {--dry-run : Parse and simulate enrichment without persisting changes}
        {--memory-limit=4G : PHP memory limit to apply before loading the workbooks}
        {--chunk=100 : Number of rows to process per sheet chunk}';

    protected $description = 'Fill missing item data from Excel workbooks and create unmatched items or groups when needed';

    public function handle(ExcelItemEnrichmentService $service): int
    {
        $this->applyMemoryLimit((string) $this->option('memory-limit'));

        $paths = array_map(
            fn (string $path): string => $this->resolvePath($path),
            (array) $this->argument('paths')
        );
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        try {
            $result = $service->enrichFiles($paths, $dryRun, $chunkSize);
        } catch (Throwable $exception) {
            $this->error('Item enrichment failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result['files'] as $fileReport) {
            $this->line('File: '.$fileReport['path']);

            foreach ($this->reportKeys() as $key) {
                $this->line($key.': '.(int) ($fileReport[$key] ?? 0));
            }
        }

        $this->line('Totals:');

        foreach ($this->reportKeys() as $key) {
            $this->line($key.': '.(int) ($result['totals'][$key] ?? 0));
        }

        if ($dryRun) {
            $this->comment('Dry run completed without persisting changes.');
        }

        return self::SUCCESS;
    }

    private function applyMemoryLimit(string $limit): void
    {
        $limit = trim($limit);

        if ($limit === '') {
            return;
        }

        $current = ini_get('memory_limit');
        $previous = @ini_set('memory_limit', $limit);

        if ($previous === false) {
            $this->warn('Unable to set memory_limit to '.$limit.'; current limit remains '.$current.'.');

            return;
        }

        if ($current !== $limit) {
            $this->line('memory_limit: '.$current.' -> '.$limit);
        }
    }

    /**
     * @return array<int, string>
     */
    private function reportKeys(): array
    {
        return [
            'rows_scanned',
            'rows_updated',
            'rows_created',
            'groups_created',
            'rows_invalid',
            'rows_skipped_noop',
            'rows_skipped_ambiguous',
            'rows_skipped_duplicate_in_file',
        ];
    }

    private function resolvePath(string $path): string
    {
        if (is_file($path)) {
            return $path;
        }

        $normalizedPath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        $candidates = [
            base_path('excel'.DIRECTORY_SEPARATOR.$normalizedPath),
            storage_path('app'.DIRECTORY_SEPARATOR.'excel'.DIRECTORY_SEPARATOR.$normalizedPath),
            base_path($path),
            storage_path($path),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Excel file not found: '.$path);
    }
}
