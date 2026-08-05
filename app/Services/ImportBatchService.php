<?php

namespace App\Services;

use App\Imports\PetraCatalogImport;
use App\Jobs\ImportBatchJob;
use App\Models\ImportBatch;
use App\Support\Excel\WindowedWorkbook;
use App\Support\Excel\WindowReadFilter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
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
        private readonly EnhancedLegacyWorkbookImportService $legacyWorkbookImporter,
    ) {}

    /**
     * Import an uploaded workbook. Non-dry-run uploads remain queued for API compatibility.
     *
     * @throws Throwable
     */
    public function import(UploadedFile $file, ?string $importedBy = null, bool $dryRun = false): array
    {
        $batch = $this->createBatch(
            $file->getClientOriginalName(),
            $importedBy,
            $dryRun ? self::STATUS_PROCESSING : self::STATUS_QUEUED,
        );

        $storedPath = $this->storeFile(
            $batch->id,
            (string) $file->getRealPath(),
            $file->getClientOriginalName(),
        );

        if ($dryRun) {
            $this->processBatch($batch, $storedPath, true);

            return $this->report($batch->fresh());
        }

        ImportBatchJob::dispatch($batch->id, $storedPath);

        return $this->report($batch);
    }

    /**
     * Queue a workbook from a local path. Retained for callers that explicitly need queue behavior.
     *
     * @throws Throwable
     */
    public function importFromPath(string $sourcePath, ?string $importedBy = null, bool $dryRun = false): array
    {
        if ($dryRun) {
            return $this->importFromPathSync($sourcePath, $importedBy, true);
        }

        $this->ensureSourceFileExists($sourcePath);
        $fileName = basename($sourcePath);
        $batch = $this->createBatch($fileName, $importedBy, self::STATUS_QUEUED);
        $storedPath = $this->storeFile($batch->id, $sourcePath, $fileName);

        ImportBatchJob::dispatch($batch->id, $storedPath);

        return $this->report($batch);
    }

    /**
     * Import a local workbook immediately in the current PHP process.
     *
     * This is used by the imports:run terminal command so no queue worker is required and
     * the command can return the real inserted/skipped/invalid totals before it exits.
     *
     * @throws Throwable
     */
    public function importFromPathSync(string $sourcePath, ?string $importedBy = null, bool $dryRun = false): array
    {
        $this->ensureSourceFileExists($sourcePath);

        $fileName = basename($sourcePath);
        $batch = $this->createBatch($fileName, $importedBy, self::STATUS_PROCESSING);
        $storedPath = $this->storeFile($batch->id, $sourcePath, $fileName);

        $this->processBatch($batch, $storedPath, $dryRun);

        return $this->report($batch->fresh());
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

            return $this->runPetraImportInWindows($import, $absolutePath, (string) ($detected['sheet_name'] ?? ''));
        }

        return $this->legacyWorkbookImporter->import($batch, $absolutePath, $dryRun);
    }

    /**
     * @return array{rows_inserted:int,rows_skipped:int,rows_invalid:int,rows_flagged:int}
     */
    private function runPetraImportInWindows(
        PetraCatalogImport $import,
        string $filePath,
        string $sheetName,
    ): array {
        if ($sheetName === '') {
            throw new RuntimeException('Petra worksheet name was not detected.');
        }

        $sheetInfo = collect(WindowedWorkbook::worksheetInfos($filePath))
            ->first(function (array $info) use ($sheetName): bool {
                return (string) ($info['worksheetName'] ?? $info['sheetName'] ?? '') === $sheetName;
            });

        if (! is_array($sheetInfo)) {
            throw new RuntimeException('Petra worksheet metadata could not be located.');
        }

        $totalRows = max(1, (int) ($sheetInfo['totalRows'] ?? 0));

        for ($chunkStart = 2; $chunkStart <= $totalRows; $chunkStart += 500) {
            $chunkEnd = min($totalRows, $chunkStart + 499);
            $reader = WindowedWorkbook::reader($filePath);
            $reader->setReadDataOnly(true);
            $reader->setLoadSheetsOnly([$sheetName]);
            $reader->setReadFilter(new WindowReadFilter($chunkStart, $chunkEnd, 7));

            $spreadsheet = $reader->load(WindowedWorkbook::path($filePath, 7, [$sheetName]));
            $sheet = $spreadsheet->getSheetByName($sheetName);

            if (! $sheet instanceof Worksheet) {
                $spreadsheet->disconnectWorksheets();

                throw new RuntimeException('Could not load worksheet: '.$sheetName);
            }

            try {
                $import->processWindow($sheet, $chunkStart, $chunkEnd);
            } finally {
                $spreadsheet->disconnectWorksheets();
                unset($sheet, $spreadsheet, $reader);
                gc_collect_cycles();
            }
        }

        return $import->report();
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
        $this->ensureSourceFileExists($sourcePath);

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

    private function ensureSourceFileExists(string $sourcePath): void
    {
        if (! is_file($sourcePath)) {
            throw new RuntimeException('Import source path does not exist.');
        }
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
