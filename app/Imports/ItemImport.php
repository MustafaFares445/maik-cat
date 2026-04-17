<?php

namespace App\Imports;

use App\Models\CarGroup;
use App\Models\ImportBatch;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ItemImport implements WithMultipleSheets
{
    public function __construct(
        private readonly ImportBatch $batch,
    ) {
    }

    public function sheets(): array
    {
        $skip = ['kitko'];

        return collect(CarGroup::all())
            ->mapWithKeys(fn (CarGroup $group) => [
                $group->excel_sheet_name => new ItemSheetImport(
                    batch: $this->batch,
                    carGroup: $group,
                ),
            ])
            ->reject(fn ($_, string $sheet) => in_array(strtolower($sheet), $skip, true))
            ->toArray();
    }
}

