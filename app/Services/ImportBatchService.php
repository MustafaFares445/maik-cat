<?php

namespace App\Services;

use App\Imports\ItemImport;
use App\Models\ImportBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ImportBatchService
{
    public function import(UploadedFile $file, string $importedBy): ImportBatch
    {
        $batch = $this->createBatch($file, $importedBy);

        try {
            DB::transaction(function () use ($file, $batch) {
                Excel::import(new ItemImport($batch), $file);
            });

            $batch->update(['status' => 'completed']);
        } catch (Throwable $e) {
            $batch->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Import batch failed', [
                'batch_id' => $batch->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }

        return $batch->fresh();
    }

    private function createBatch(UploadedFile $file, string $importedBy): ImportBatch
    {
        return ImportBatch::create([
            'id' => Str::uuid(),
            'file_name' => $file->getClientOriginalName(),
            'imported_by' => $importedBy,
            'status' => 'processing',
        ]);
    }
}

