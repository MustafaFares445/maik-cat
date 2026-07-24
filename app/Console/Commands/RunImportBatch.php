<?php

namespace App\Console\Commands;

use App\Services\ImportBatchService;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

class RunImportBatch extends Command
{
    protected $signature = 'imports:run
        {path? : Optional .xls/.xlsx file or directory; defaults to the configured excel folder}
        {--dry-run : Parse and profile all workbooks without writing items}
        {--imported-by= : Optional importer identity/email}';

    protected $description = 'Import one Excel workbook or every workbook in a directory immediately';

    public function handle(ImportBatchService $importBatchService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $importedBy = $this->option('imported-by');
        $importedBy = is_string($importedBy) && trim($importedBy) !== '' ? trim($importedBy) : null;

        try {
            $source = $this->resolveSource($this->argument('path'));
            $files = $this->discoverExcelFiles($source);
        } catch (Throwable $exception) {
            $this->error('Import discovery failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Excel source: '.$source);
        $this->line('Excel files found: '.count($files));
        $this->line($dryRun
            ? 'Mode: dry run — no item data will be changed.'
            : 'Mode: direct import — no queue worker is required.');
        $this->newLine();

        $rows = [];
        $totals = [
            'inserted' => 0,
            'skipped' => 0,
            'flagged' => 0,
            'invalid' => 0,
            'duplicates' => 0,
            'failed' => 0,
            'duration' => 0.0,
        ];

        $progress = $this->output->createProgressBar(count($files));
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %message%');
        $progress->setMessage('Starting');
        $progress->start();

        foreach ($files as $file) {
            $displayPath = $this->displayPath($file, $source);
            $progress->setMessage('Processing '.$displayPath);
            $progress->display();
            $startedAt = microtime(true);

            try {
                $report = $importBatchService->importFromPathSync($file, $importedBy, $dryRun);
                $duration = microtime(true) - $startedAt;
                $inserted = (int) ($report['rows_inserted'] ?? 0);
                $skipped = (int) ($report['rows_skipped'] ?? 0);
                $flagged = (int) ($report['rows_flagged'] ?? 0);
                $invalid = (int) ($report['rows_invalid'] ?? 0);
                $duplicates = (int) ($report['duplicates_total'] ?? 0);

                $totals['inserted'] += $inserted;
                $totals['skipped'] += $skipped;
                $totals['flagged'] += $flagged;
                $totals['invalid'] += $invalid;
                $totals['duplicates'] += $duplicates;
                $totals['duration'] += $duration;

                $rows[] = [
                    $displayPath,
                    (string) ($report['status'] ?? 'unknown'),
                    $inserted,
                    $skipped,
                    $flagged,
                    $invalid,
                    $duplicates,
                    $this->formatDuration($duration),
                    (string) ($report['batch_id'] ?? 'n/a'),
                ];

                $progress->setMessage('Completed '.$displayPath);
            } catch (Throwable $exception) {
                $duration = microtime(true) - $startedAt;
                $totals['failed']++;
                $totals['duration'] += $duration;

                $rows[] = [
                    $displayPath,
                    'failed: '.$exception->getMessage(),
                    0,
                    0,
                    0,
                    0,
                    0,
                    $this->formatDuration($duration),
                    'n/a',
                ];

                $progress->setMessage('Failed '.$displayPath);
            }

            $progress->advance();
            $progress->display();
            gc_collect_cycles();
        }

        $progress->finish();
        $this->newLine(2);

        $this->table(
            ['File', 'Status', 'Inserted', 'Skipped', 'Flagged', 'Invalid', 'Duplicates', 'Duration', 'Batch ID'],
            $rows,
        );

        $this->newLine();
        $this->line('Combined result');
        $this->table(
            ['Files', 'Failed', 'Inserted', 'Skipped', 'Flagged', 'Invalid', 'Duplicates', 'Duration'],
            [[
                count($files),
                $totals['failed'],
                $totals['inserted'],
                $totals['skipped'],
                $totals['flagged'],
                $totals['invalid'],
                $totals['duplicates'],
                $this->formatDuration($totals['duration']),
            ]],
        );

        if ($dryRun) {
            $this->comment('Dry run completed. No item, extra-code, or media data was changed.');
        } else {
            $this->info('Import completed directly. No queue worker is required.');
        }

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveSource(mixed $argument): string
    {
        if (is_string($argument) && trim($argument) !== '') {
            $path = trim($argument);
            $candidates = [
                $path,
                base_path($path),
                storage_path($path),
                storage_path('app/'.$path),
            ];
        } else {
            $configured = config('imports.excel_directory');
            $candidates = [
                is_string($configured) ? $configured : null,
                base_path('excel'),
                storage_path('app/excel'),
            ];
        }

        foreach (array_filter($candidates, static fn (mixed $path): bool => is_string($path) && trim($path) !== '') as $candidate) {
            $resolved = realpath($candidate);

            if ($resolved !== false && (is_file($resolved) || is_dir($resolved))) {
                return $resolved;
            }
        }

        throw new RuntimeException(
            is_string($argument) && trim($argument) !== ''
                ? 'The supplied Excel file or directory does not exist.'
                : 'The configured Excel folder does not exist. Set EXCEL_IMPORT_DIRECTORY or create the project `excel` folder.',
        );
    }

    /** @return list<string> */
    private function discoverExcelFiles(string $source): array
    {
        if (is_file($source)) {
            if (! $this->isExcelFile(new SplFileInfo($source))) {
                throw new RuntimeException('The supplied file must use the .xls or .xlsx extension.');
            }

            return [$source];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $this->isExcelFile($file)) {
                continue;
            }

            $realPath = $file->getRealPath();

            if (is_string($realPath)) {
                $files[] = $realPath;
            }
        }

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        if ($files === []) {
            throw new RuntimeException('No .xls or .xlsx files were found in the Excel directory.');
        }

        return $files;
    }

    private function isExcelFile(SplFileInfo $file): bool
    {
        if (! $file->isFile() || str_starts_with($file->getFilename(), '~$')) {
            return false;
        }

        return in_array(strtolower($file->getExtension()), ['xls', 'xlsx'], true);
    }

    private function displayPath(string $file, string $source): string
    {
        if (! is_dir($source)) {
            return basename($file);
        }

        $relative = ltrim(substr($file, strlen(rtrim($source, DIRECTORY_SEPARATOR))), DIRECTORY_SEPARATOR);

        return $relative !== '' ? $relative : basename($file);
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return number_format($seconds, 2).'s';
        }

        $minutes = intdiv((int) $seconds, 60);
        $remainingSeconds = $seconds - ($minutes * 60);

        return $minutes.'m '.number_format($remainingSeconds, 1).'s';
    }
}
