<?php

namespace App\Services;

use App\Imports\ItemImport;
use App\Imports\PetraCatalogImport;
use App\Models\ImportBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ImportBatchService
{
    public function __construct(private readonly ImportFormatDetector $formatDetector) {}

    /**
     * @throws Throwable
     */
    public function import(UploadedFile $file, ?string $importedBy = null): array
    {
        $batch = ImportBatch::query()->create([
            'file_name' => $file->getClientOriginalName(),
            'imported_by' => filled($importedBy) ? $importedBy : 'system@local',
            'status' => 'processing',
            'error_message' => null,
            'rows_inserted' => 0,
            'rows_skipped' => 0,
            'rows_flagged' => 0,
            'rows_invalid' => 0,
        ]);

        try {
            $report = DB::transaction(function () use ($file, $batch): array {
                $detected = $this->formatDetector->detect($file);

                if (($detected['format'] ?? null) === ImportFormatDetector::FORMAT_PETRA) {
                    $import = new PetraCatalogImport(
                        $batch,
                        (string) ($detected['sheet_name'] ?? '')
                    );

                    Excel::import($import, $file);

                    return $import->report();
                }

                $import = new ItemImport();
                Excel::import($import, $file);

                return array_merge($import->report(), [
                    'rows_flagged' => 0,
                ]);
            });

            $batch->update([
                'status' => 'completed',
                'error_message' => null,
                'rows_inserted' => (int) ($report['rows_inserted'] ?? 0),
                'rows_skipped' => (int) ($report['rows_skipped'] ?? 0),
                'rows_flagged' => (int) ($report['rows_flagged'] ?? 0),
                'rows_invalid' => (int) ($report['rows_invalid'] ?? 0),
            ]);
        } catch (Throwable $e) {
            $batch->update([
                'status' => 'failed',
                'error_message' => Str::limit($e->getMessage(), 60000),
            ]);

            Log::error('Import failed', [
                'batch_id' => $batch->id,
                'file_name' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }

        $batch->refresh();

        return [
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'rows_inserted' => $batch->rows_inserted,
            'rows_skipped' => $batch->rows_skipped,
            'rows_flagged' => $batch->rows_flagged,
            'rows_invalid' => $batch->rows_invalid,
        ];
    }
}
