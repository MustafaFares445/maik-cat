<?php

namespace App\Support\Excel;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

final class WindowReadFilter implements IReadFilter
{
    public function __construct(
        private readonly int $startRow,
        private readonly int $endRow,
        private readonly int $maxColumn = 25,
    ) {}

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        if ($row < $this->startRow || $row > $this->endRow) {
            return false;
        }

        return Coordinate::columnIndexFromString($columnAddress) <= $this->maxColumn;
    }
}
