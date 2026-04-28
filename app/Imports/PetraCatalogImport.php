<?php

namespace App\Imports;

use App\Models\ImportBatch;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class PetraCatalogImport implements WithMultipleSheets
{
    private PetraSheetImport $sheetImport;

    public function __construct(ImportBatch $batch, private readonly string $sheetName)
    {
        $this->sheetImport = new PetraSheetImport($batch, $sheetName);
    }

    public function sheets(): array
    {
        return [
            $this->sheetName => $this->sheetImport,
        ];
    }

    public function report(): array
    {
        return $this->sheetImport->report();
    }
}
