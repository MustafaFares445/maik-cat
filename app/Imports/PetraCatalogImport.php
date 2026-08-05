<?php

namespace App\Imports;

use App\Models\ImportBatch;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PetraCatalogImport implements WithMultipleSheets
{
    private PetraSheetImport $sheetImport;

    public function __construct(
        ImportBatch $batch,
        private readonly string $sheetName,
        private readonly bool $dryRun = false,
    ) {
        $this->sheetImport = new PetraSheetImport($batch, $sheetName, $dryRun);
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

    public function processWindow(Worksheet $sheet, int $startRow, int $endRow): void
    {
        $this->sheetImport->processWorksheetWindow($sheet, $startRow, $endRow);
    }
}
