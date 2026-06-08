<?php

namespace App\Console\Commands;

use App\Services\Ecotrade\EcotradeSourceRepairService;
use App\Services\ExcelItemEnrichmentService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class RepairImportedSourceDataCommand extends Command
{
    protected $signature = 'imports:repair-source-data
        {paths* : One or more JSON or Excel source files}
        {--dry-run : Parse and simulate repairs without persisting changes}
        {--memory-limit=4G : PHP memory limit to apply before loading workbooks}
        {--chunk=100 : Number of rows to process per Excel sheet chunk}';

    protected $description = 'Repair imported item data from Ecotrade JSON files and Excel workbooks';

    public function handle(
        EcotradeSourceRepairService $jsonRepairService,
        ExcelItemEnrichmentService $excelRepairService,
    ): int {
        $this->applyMemoryLimit((string) $this->option('memory-limit'));

        $paths = array_map(
            fn (string $path): string => $this->resolvePath($path),
            (array) $this->argument('paths')
        );
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $files = [];
        $totals = array_fill_keys($this->reportKeys(), 0);

        try {
            foreach ($paths as $path) {
                $result = $this->isJsonPath($path)
                    ? $jsonRepairService->repairFiles([$path], $dryRun)
                    : $excelRepairService->repairFiles([$path], $dryRun, $chunkSize);

                $fileReport = $result['files'][0] ?? ['path' => $path];
                $files[] = $fileReport;

                foreach ($this->reportKeys() as $key) {
                    $totals[$key] += (int) ($fileReport[$key] ?? 0);
                }
            }
        } catch (Throwable $exception) {
            $this->error('Repair failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        foreach ($files as $fileReport) {
            $this->line('File: '.$fileReport['path']);

            foreach ($this->reportKeys() as $key) {
                $this->line($key.': '.(int) ($fileReport[$key] ?? 0));
            }
        }

        $this->line('Totals:');

        foreach ($this->reportKeys() as $key) {
            $this->line($key.': '.(int) ($totals[$key] ?? 0));
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
            'rows_skipped_not_found',
            'rows_skipped_duplicate_in_file',
        ];
    }

    private function isJsonPath(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'json';
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

        throw new RuntimeException('Source file not found: '.$path);
    }
}
