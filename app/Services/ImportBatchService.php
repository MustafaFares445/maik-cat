<?php

namespace App\Services;

use App\Imports\ItemImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ImportBatchService
{
    public function import(UploadedFile $file): array
    {
        $import = new ItemImport();

        try {
            DB::transaction(function () use ($file, $import) {
                Excel::import($import, $file);
            });
        } catch (Throwable $e) {
            Log::error('Import failed', [
                'file_name' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }

        return array_merge([
            'status' => 'completed',
        ], $import->report());
    }
}
