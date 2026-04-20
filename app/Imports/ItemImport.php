<?php

namespace App\Imports;

use App\Models\CarGroup;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ItemImport implements WithMultipleSheets
{
    private array $sheetImports = [];

    public function __construct($unused = null) {}

    public function sheets(): array
    {
        $skip = ['kitko'];

        $this->sheetImports = collect(CarGroup::all())
            ->mapWithKeys(function (CarGroup $group) {
                $import = new ItemSheetImport($group);

                return [
                    $group->excel_sheet_name => $import,
                ];
            })
            ->reject(fn($_, string $sheet) => in_array(strtolower($sheet), $skip, true))
            ->toArray();

        return $this->sheetImports;
    }

    public function report(): array
    {
        return [
            'rows_inserted' => collect($this->sheetImports)->sum(fn(ItemSheetImport $sheet) => $sheet->insertedCount()),
            'rows_skipped' => collect($this->sheetImports)->sum(fn(ItemSheetImport $sheet) => $sheet->skippedCount()),
            'rows_invalid' => collect($this->sheetImports)->sum(fn(ItemSheetImport $sheet) => $sheet->invalidCount()),
        ];
    }
}
