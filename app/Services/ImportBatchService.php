<?php

namespace App\Services;

use App\Imports\PetraCatalogImport;
use App\Jobs\ImportBatchJob;
use App\Models\ImportBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Throwable;

class ImportBatchService
{
    private const STATUS_QUEUED = 'queued';

    private const STATUS_PROCESSING = 'processing';

    private const STATUS_COMPLETED = 'completed';

    private const STATUS_PREVIEW_COMPLETED = 'preview_completed';

    private const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly ImportFormatDetector $formatDetector,
        private readonly LegacyWorkbookImportService $legacyWorkbookImporter,
    ) {}

    /**
     * @throws Throwable
     */
    public function import(UploadedFile $file, ?string $importedBy = null, bool $dryRun = false): array
    {
        $batch = $this->createBatch(
            $file->getClientOriginalName(),
            $importedBy,
            $dryRun ? self::STATUS_PROCESSING : self::STATUS_QUEUED
        );

        $storedPath = $this->storeFile(
            $batch->id,
            (string) $file->getRealPath(),
            $file->getClientOriginalName()
        );

        if ($dryRun) {
            $this->processBatch($batch, $storedPath, true);

            return $this->report($batch->fresh());
        }

        ImportBatchJob::dispatch($batch->id, $storedPath);

        return $this->report($batch);
    }

    /**
     * @throws Throwable
     */
    public function importFromPath(string $sourcePath, ?string $importedBy = null, bool $dryRun = false): array
    {
        if (! is_file($sourcePath)) {
            throw new RuntimeException('Import source path does not exist.');
        }

        $fileName = basename($sourcePath);

        $batch = $this->createBatch(
            $fileName,
            $importedBy,
            $dryRun ? self::STATUS_PROCESSING : self::STATUS_QUEUED
        );

        $storedPath = $this->storeFile($batch->id, $sourcePath, $fileName);

        if ($dryRun) {
            $this->processBatch($batch, $storedPath, true);

            return $this->report($batch->fresh());
        }

        ImportBatchJob::dispatch($batch->id, $storedPath);

        return $this->report($batch);
    }

    /**
     * @throws Throwable
     */
    public function processQueuedBatch(string $batchId, string $storedPath): void
    {
        $batch = ImportBatch::query()->findOrFail($batchId);

        $this->processBatch($batch, $storedPath, false);
    }

    /**
     * @throws Throwable
     */
    private function processBatch(ImportBatch $batch, string $storedPath, bool $dryRun): void
    {
        $batch->update([
            'status' => self::STATUS_PROCESSING,
            'error_message' => null,
        ]);

        $absolutePath = Storage::disk('local')->path($storedPath);

        if (! is_file($absolutePath)) {
            throw new RuntimeException('Stored import file is missing.');
        }

        try {
            $report = DB::transaction(function () use ($batch, $absolutePath, $dryRun): array {
                return $this->runImport($batch, $absolutePath, $dryRun);
            });

            $batch->update([
                'status' => $dryRun ? self::STATUS_PREVIEW_COMPLETED : self::STATUS_COMPLETED,
                'error_message' => null,
                'rows_inserted' => (int) ($report['rows_inserted'] ?? 0),
                'rows_skipped' => (int) ($report['rows_skipped'] ?? 0),
                'rows_flagged' => (int) ($report['rows_flagged'] ?? 0),
                'rows_invalid' => (int) ($report['rows_invalid'] ?? 0),
            ]);
        } catch (Throwable $e) {
            $batch->update([
                'status' => self::STATUS_FAILED,
                'error_message' => Str::limit($e->getMessage(), 60000),
            ]);

            Log::error('Import failed', [
                'batch_id' => $batch->id,
                'file_name' => $batch->file_name,
                'stored_path' => $storedPath,
                'dry_run' => $dryRun,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    private function runImport(ImportBatch $batch, string $absolutePath, bool $dryRun): array
    {
        $detected = $this->formatDetector->detectFromPath($absolutePath);

        if (($detected['format'] ?? null) === ImportFormatDetector::FORMAT_PETRA) {
            $import = new PetraCatalogImport(
                $batch,
                (string) ($detected['sheet_name'] ?? ''),
                $dryRun,
            );

            Excel::import($import, $absolutePath);

            return $import->report();
        }

        return $this->legacyWorkbookImporter->import($batch, $absolutePath, $dryRun);
    }

    private function createBatch(string $fileName, ?string $importedBy, string $status): ImportBatch
    {
        return ImportBatch::query()->create([
            'file_name' => $fileName,
            'imported_by' => filled($importedBy) ? $importedBy : 'system@local',
            'status' => $status,
            'error_message' => null,
            'rows_inserted' => 0,
            'rows_skipped' => 0,
            'rows_flagged' => 0,
            'rows_invalid' => 0,
        ]);
    }

    private function storeFile(string $batchId, string $sourcePath, string $originalName): string
    {
        if (! is_file($sourcePath)) {
            throw new RuntimeException('Source file cannot be read.');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $extension = $extension !== '' ? $extension : strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $extension = $extension !== '' ? $extension : 'xlsx';

        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $baseName = preg_replace('/[^\pL\pN\-\._]+/u', '_', (string) $baseName) ?? 'import';
        $baseName = trim($baseName, '_');
        $baseName = $baseName !== '' ? $baseName : 'import';

        $relativePath = "imports/{$batchId}/{$baseName}.{$extension}";

        $stream = fopen($sourcePath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Could not open source file stream.');
        }

        try {
            Storage::disk('local')->put($relativePath, $stream);
        } finally {
            fclose($stream);
        }

        return $relativePath;
    }

    private function report(ImportBatch $batch): array
    {
        $batch->loadCount([
            'duplicateReviews as duplicates_total',
            'duplicateReviews as duplicates_pending' => fn ($query) => $query->where('status', 'pending'),
            'rowIssues as issues_total',
        ]);

        return [
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'rows_inserted' => $batch->rows_inserted,
            'rows_skipped' => $batch->rows_skipped,
            'rows_flagged' => $batch->rows_flagged,
            'rows_invalid' => $batch->rows_invalid,
            'duplicates_total' => (int) ($batch->duplicates_total ?? 0),
            'duplicates_pending' => (int) ($batch->duplicates_pending ?? 0),
            'issues_total' => (int) ($batch->issues_total ?? 0),
        ];
    }
}
