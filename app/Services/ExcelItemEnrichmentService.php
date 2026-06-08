<?php

namespace App\Services;

use App\Data\ExcelItemRowData;
use App\Models\CarGroup;
use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

class ExcelItemEnrichmentService
{
    /** @var array<string, true> */
    private array $seenSignatures = [];

    /** @var array<string, CarGroup> */
    private array $groupCache = [];

    /** @var array<string, CarGroup|null> */
    private array $existingGroupCache = [];

    /** @var array<string, Collection<int, Item>> */
    private array $serialItemCache = [];

    /** @var array<string, Collection<int, Item>> */
    private array $repairItemCache = [];

    public function __construct(
        private readonly ImportFormatDetector $formatDetector,
        private readonly ImportSheetGroupResolver $groupResolver,
    ) {}

    /**
     * @param  array<int, string>  $paths
     * @return array{
     *   files: array<int, array<string, int|string>>,
     *   totals: array<string, int>
     * }
     */
    public function enrichFiles(array $paths, bool $dryRun = false, int $chunkSize = 250): array
    {
        $this->resetRunState();

        if ($dryRun) {
            return $this->runDry(function () use ($paths, $chunkSize): array {
                return $this->processFiles($paths, $chunkSize, false);
            });
        }

        return $this->processFiles($paths, $chunkSize, false);
    }

    /**
     * @param  array<int, string>  $paths
     * @return array{
     *   files: array<int, array<string, int|string>>,
     *   totals: array<string, int>
     * }
     */
    public function repairFiles(array $paths, bool $dryRun = false, int $chunkSize = 250): array
    {
        $this->resetRunState();

        if ($dryRun) {
            return $this->runDry(function () use ($paths, $chunkSize): array {
                return $this->processFiles($paths, $chunkSize, true);
            });
        }

        return $this->processFiles($paths, $chunkSize, true);
    }

    /**
     * @param  array<int, string>  $paths
     * @return array{
     *   files: array<int, array<string, int|string>>,
     *   totals: array<string, int>
     * }
     */
    private function processFiles(array $paths, int $chunkSize, bool $repairMode): array
    {
        $files = [];

        foreach ($paths as $path) {
            $this->resetRunState();
            $files[] = $this->processFile($path, $chunkSize, $repairMode);
            gc_collect_cycles();
        }

        return [
            'files' => $files,
            'totals' => $this->sumReports($files),
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function processFile(string $path, int $chunkSize, bool $repairMode): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Import source file does not exist: '.$path);
        }

        $report = $this->makeEmptyReport($path);
        $detected = $this->formatDetector->detectFromPath($path);
        $sheetInfos = $this->worksheetInfos($path);

        if (($detected['format'] ?? null) === ImportFormatDetector::FORMAT_PETRA) {
            $sheetName = (string) ($detected['sheet_name'] ?? '');
            if ($sheetName === '') {
                throw new RuntimeException('Petra worksheet name was not detected.');
            }

            $sheetInfo = $this->worksheetInfoByName($sheetInfos, $sheetName);
            if (! is_array($sheetInfo)) {
                throw new RuntimeException('Petra worksheet metadata could not be located.');
            }

            $totalRows = max(1, (int) ($sheetInfo['totalRows'] ?? 0));
            for ($chunkStart = 2; $chunkStart <= $totalRows; $chunkStart += $chunkSize) {
                $chunkEnd = min($totalRows, $chunkStart + $chunkSize - 1);
                [$spreadsheet, $sheet] = $this->loadWorksheetWindow($path, $sheetName, $chunkStart, $chunkEnd, 25);

                try {
                    $this->processPetraSheetWindow($sheet, $report, $chunkStart, $chunkEnd, $repairMode);
                } finally {
                    $spreadsheet->disconnectWorksheets();
                    unset($sheet, $spreadsheet);
                    gc_collect_cycles();
                }
            }

            return $report;
        }

        foreach ($sheetInfos as $sheetInfo) {
            $sheetName = $this->worksheetName($sheetInfo);
            if ($sheetName === null) {
                continue;
            }

            $totalRows = max(1, (int) ($sheetInfo['totalRows'] ?? 0));
            if ($totalRows <= 0) {
                continue;
            }

            $previewEnd = min(20, $totalRows);
            [$previewSpreadsheet, $previewSheet] = $this->loadWorksheetWindow($path, $sheetName, 1, $previewEnd, 25);

            try {
                if ($this->shouldSkipLegacySheet($previewSheet)) {
                    continue;
                }

                $layout = $this->detectLegacyLayout($previewSheet);
                $canonicalSheetName = $this->canonicalGroupName($previewSheet->getTitle());
                $existingGroup = $this->findExistingGroup($canonicalSheetName);
                $fallbackModel = $existingGroup?->name ?? $canonicalSheetName;
                $startRow = max((int) $layout['start_row'], 1);

                for ($chunkStart = $startRow; $chunkStart <= $totalRows; $chunkStart += $chunkSize) {
                    $chunkEnd = min($totalRows, $chunkStart + $chunkSize - 1);
                    [$spreadsheet, $sheet] = $this->loadWorksheetWindow($path, $sheetName, $chunkStart, $chunkEnd, 25);

                    try {
                        $this->processLegacySheetWindow(
                            $sheet,
                            $report,
                            $layout,
                            $canonicalSheetName,
                            $fallbackModel,
                            $chunkStart,
                            $chunkEnd,
                            $repairMode,
                        );
                    } finally {
                        $spreadsheet->disconnectWorksheets();
                        unset($sheet, $spreadsheet);
                        gc_collect_cycles();
                    }
                }
            } finally {
                $previewSpreadsheet->disconnectWorksheets();
                unset($previewSheet, $previewSpreadsheet);
                gc_collect_cycles();
            }
        }

        return $report;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function worksheetInfos(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        return $reader->listWorksheetInfo($path);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sheetInfos
     * @return array<string, mixed>|null
     */
    private function worksheetInfoByName(array $sheetInfos, string $sheetName): ?array
    {
        $normalizedName = Str::upper(trim($sheetName));

        foreach ($sheetInfos as $sheetInfo) {
            if ($this->worksheetName($sheetInfo) === null) {
                continue;
            }

            if (Str::upper(trim($this->worksheetName($sheetInfo))) === $normalizedName) {
                return $sheetInfo;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $sheetInfo
     */
    private function worksheetName(array $sheetInfo): ?string
    {
        $name = (string) ($sheetInfo['worksheetName'] ?? $sheetInfo['sheetName'] ?? '');

        return trim($name) === '' ? null : $name;
    }

    /**
     * @return array{0: Spreadsheet, 1: Worksheet}
     */
    private function loadWorksheetWindow(string $path, string $sheetName, int $startRow, int $endRow, int $maxColumn): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        $reader->setLoadSheetsOnly([$sheetName]);
        $reader->setReadFilter(new \App\Support\Excel\WindowReadFilter($startRow, $endRow, $maxColumn));

        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName($sheetName);

        if (! $sheet instanceof Worksheet) {
            $spreadsheet->disconnectWorksheets();

            throw new RuntimeException('Could not load worksheet: '.$sheetName);
        }

        return [$spreadsheet, $sheet];
    }

    /**
     * @param  array<string, int|string>  $report
     */
    private function processPetraSheetWindow(Worksheet $sheet, array &$report, int $startRow, int $endRow, bool $repairMode): void
    {
        for ($rowIndex = $startRow; $rowIndex <= $endRow; $rowIndex++) {
            $mapped = $this->mapPetraRow($sheet, $rowIndex);

            if (! $this->isPotentialPetraRow($mapped)) {
                continue;
            }

            $report['rows_scanned']++;

            if (
                blank($mapped['serial_code'])
                || blank($mapped['model'])
                || ! $this->hasEnrichmentValues($mapped)
            ) {
                $report['rows_invalid']++;

                continue;
            }

            $row = new ExcelItemRowData(
                sheetName: $sheet->getTitle(),
                rowIndex: $rowIndex,
                groupName: $this->canonicalGroupName((string) $mapped['model']),
                model: $mapped['model'],
                serialCode: (string) $mapped['serial_code'],
                normalizedSerial: Item::normalizeSerialValue($mapped['serial_code']),
                details: $mapped['details'],
                weightKg: $mapped['weight_kg'],
                ptPpm: $mapped['pt_ppm'],
                pdPpm: $mapped['pd_ppm'],
                rhPpm: $mapped['rh_ppm'],
                shapeCode: null,
            );

            if ($repairMode) {
                $this->processRepairRow($row, $report);

                continue;
            }

            $this->processRow($row, $report);
        }
    }

    /**
     * @param  array<string, int|string>  $report
     * @param  array<string, int>  $layout
     */
    private function processLegacySheetWindow(
        Worksheet $sheet,
        array &$report,
        array $layout,
        string $canonicalSheetName,
        string $fallbackModel,
        int $startRow,
        int $endRow,
        bool $repairMode = false,
    ): void {
        for ($rowIndex = $startRow; $rowIndex <= $endRow; $rowIndex++) {
            $mapped = $this->mapLegacyRow($sheet, $rowIndex, $layout, $fallbackModel);

            if (! $this->isPotentialLegacyRow($mapped)) {
                continue;
            }

            $report['rows_scanned']++;

            if ($this->isInvalidLegacyRow($sheet, $rowIndex, $layout, $mapped)) {
                $report['rows_invalid']++;

                continue;
            }

            $row = new ExcelItemRowData(
                sheetName: $sheet->getTitle(),
                rowIndex: $rowIndex,
                groupName: $canonicalSheetName,
                model: $mapped['model'],
                serialCode: (string) $mapped['serial_code'],
                normalizedSerial: Item::normalizeSerialValue($mapped['serial_code']),
                details: $mapped['details'],
                weightKg: $mapped['weight_kg'],
                ptPpm: $mapped['pt_ppm'],
                pdPpm: $mapped['pd_ppm'],
                rhPpm: $mapped['rh_ppm'],
                shapeCode: $mapped['shape_code'],
            );

            if ($repairMode) {
                $this->processRepairRow($row, $report);

                continue;
            }

            $this->processRow($row, $report);
        }
    }

    /**
     * @param  array<string, int|string>  $report
     */
    private function processPetraSheet(Worksheet $sheet, array &$report): void
    {
        $highestRow = $sheet->getHighestDataRow();

        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
            $mapped = $this->mapPetraRow($sheet, $rowIndex);

            if (! $this->isPotentialPetraRow($mapped)) {
                continue;
            }

            $report['rows_scanned']++;

            if (
                blank($mapped['serial_code'])
                || blank($mapped['model'])
                || ! $this->hasEnrichmentValues($mapped)
            ) {
                $report['rows_invalid']++;

                continue;
            }

            $row = new ExcelItemRowData(
                sheetName: $sheet->getTitle(),
                rowIndex: $rowIndex,
                groupName: $this->canonicalGroupName((string) $mapped['model']),
                model: $mapped['model'],
                serialCode: (string) $mapped['serial_code'],
                normalizedSerial: Item::normalizeSerialValue($mapped['serial_code']),
                details: $mapped['details'],
                weightKg: $mapped['weight_kg'],
                ptPpm: $mapped['pt_ppm'],
                pdPpm: $mapped['pd_ppm'],
                rhPpm: $mapped['rh_ppm'],
                shapeCode: null,
            );

            $this->processRow($row, $report);
        }
    }

    /**
     * @param  array<string, int|string>  $report
     */
    private function processLegacySheet(Worksheet $sheet, array &$report): void
    {
        $layout = $this->detectLegacyLayout($sheet);
        $canonicalSheetName = $this->canonicalGroupName($sheet->getTitle());
        $existingGroup = $this->findExistingGroup($canonicalSheetName);
        $fallbackModel = $existingGroup?->name ?? $canonicalSheetName;
        $highestRow = $sheet->getHighestDataRow();

        for ($rowIndex = (int) $layout['start_row']; $rowIndex <= $highestRow; $rowIndex++) {
            $mapped = $this->mapLegacyRow($sheet, $rowIndex, $layout, $fallbackModel);

            if (! $this->isPotentialLegacyRow($mapped)) {
                continue;
            }

            $report['rows_scanned']++;

            if ($this->isInvalidLegacyRow($sheet, $rowIndex, $layout, $mapped)) {
                $report['rows_invalid']++;

                continue;
            }

            $row = new ExcelItemRowData(
                sheetName: $sheet->getTitle(),
                rowIndex: $rowIndex,
                groupName: $canonicalSheetName,
                model: $mapped['model'],
                serialCode: (string) $mapped['serial_code'],
                normalizedSerial: Item::normalizeSerialValue($mapped['serial_code']),
                details: $mapped['details'],
                weightKg: $mapped['weight_kg'],
                ptPpm: $mapped['pt_ppm'],
                pdPpm: $mapped['pd_ppm'],
                rhPpm: $mapped['rh_ppm'],
                shapeCode: $mapped['shape_code'],
            );

            $this->processRow($row, $report);
        }
    }

    /**
     * @param  array<string, int|string>  $report
     */
    private function processRow(ExcelItemRowData $row, array &$report): void
    {
        $signature = $row->duplicateSignature();

        if (isset($this->seenSignatures[$signature])) {
            $report['rows_skipped_duplicate_in_file']++;

            return;
        }

        $this->seenSignatures[$signature] = true;

        $group = $this->resolveGroup($row->groupName);

        if ($group->wasRecentlyCreated) {
            $report['groups_created']++;
        }

        $matches = $this->existingSameSerial($group->id, $row->normalizedSerial);

        if ($matches->count() > 1) {
            $report['rows_skipped_ambiguous']++;

            return;
        }

        if ($matches->isEmpty()) {
            $item = Item::query()->create([
                'id' => (string) Str::uuid(),
                'car_group_id' => $group->id,
                'model' => $row->model,
                'serial_code' => $row->serialCode,
                'normalized_serial' => $row->normalizedSerial,
                'weight_kg' => $row->weightKg,
                'pt_ppm' => $row->ptPpm,
                'pd_ppm' => $row->pdPpm,
                'rh_ppm' => $row->rhPpm,
                'shape_code' => $row->shapeCode,
                'details' => $row->details,
                'source' => 'excel_import',
            ]);

            $this->appendItemCache($group->id, $row->normalizedSerial, $item);
            $report['rows_created']++;

            return;
        }

        $item = $matches->first();

        if (! $item instanceof Item) {
            $report['rows_skipped_ambiguous']++;

            return;
        }

        $updates = $this->missingFieldUpdates($item, $row);

        if ($updates === []) {
            $report['rows_skipped_noop']++;

            return;
        }

        $item->fill($updates);
        $item->save();

        $report['rows_updated']++;
    }

    /**
     * @param  array<string, int|string>  $report
     */
    private function processRepairRow(ExcelItemRowData $row, array &$report): void
    {
        $signature = $row->duplicateSignature();

        if (isset($this->seenSignatures[$signature])) {
            $report['rows_skipped_duplicate_in_file']++;

            return;
        }

        $this->seenSignatures[$signature] = true;

        $group = $this->resolveGroup($row->groupName);

        if ($group->wasRecentlyCreated) {
            $report['groups_created']++;
        }

        $matches = $this->repairCandidates($group->id, $row);

        if ($matches->count() > 1) {
            $report['rows_skipped_ambiguous']++;

            return;
        }

        if ($matches->isEmpty()) {
            $report['rows_skipped_not_found']++;

            return;
        }

        $item = $matches->first();

        if (! $item instanceof Item) {
            $report['rows_skipped_not_found']++;

            return;
        }

        $updates = $this->repairFieldUpdates($item, $row, $group->id);

        if ($updates === []) {
            $report['rows_skipped_noop']++;

            return;
        }

        $item->fill($updates);
        $item->save();

        $report['rows_updated']++;
    }

    /**
     * @return Collection<int, Item>
     */
    private function repairCandidates(string $groupId, ExcelItemRowData $row): Collection
    {
        $cacheKey = implode('|', [
            $groupId,
            mb_strtoupper(trim((string) $row->model), 'UTF-8'),
            $this->decimal($row->weightKg, 3),
            $this->decimal($row->ptPpm, 4),
            $this->decimal($row->pdPpm, 4),
            $this->decimal($row->rhPpm, 4),
            mb_strtoupper(trim((string) $row->shapeCode), 'UTF-8'),
            mb_strtolower(trim((string) $row->details), 'UTF-8'),
        ]);

        if (! array_key_exists($cacheKey, $this->repairItemCache)) {
            $this->repairItemCache[$cacheKey] = $this->buildRepairQuery($groupId, $row, true)
                ->orderByDesc('created_at')
                ->get();

            if ($this->repairItemCache[$cacheKey]->isEmpty() && filled($row->details)) {
                $this->repairItemCache[$cacheKey] = $this->buildRepairQuery($groupId, $row, false)
                    ->orderByDesc('created_at')
                    ->get();
            }
        }

        return $this->repairItemCache[$cacheKey];
    }

    private function buildRepairQuery(string $groupId, ExcelItemRowData $row, bool $includeDetails): \Illuminate\Database\Eloquent\Builder
    {
        $query = Item::query()
            ->where('source', 'excel_import')
            ->where('car_group_id', $groupId)
            ->whereRaw('UPPER(COALESCE(model, \'\')) = ?', [mb_strtoupper(trim((string) $row->model), 'UTF-8')]);

        if ($row->weightKg !== null) {
            $query->where('weight_kg', $row->weightKg);
        }

        if ($row->ptPpm !== null) {
            $query->where('pt_ppm', $row->ptPpm);
        }

        if ($row->pdPpm !== null) {
            $query->where('pd_ppm', $row->pdPpm);
        }

        if ($row->rhPpm !== null) {
            $query->where('rh_ppm', $row->rhPpm);
        }

        if (filled($row->shapeCode)) {
            $query->whereRaw('UPPER(COALESCE(shape_code, \'\')) = ?', [mb_strtoupper(trim((string) $row->shapeCode), 'UTF-8')]);
        }

        if ($includeDetails && filled($row->details)) {
            $query->where('details', $row->details);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function repairFieldUpdates(Item $item, ExcelItemRowData $row, string $groupId): array
    {
        $updates = [];

        if ($item->car_group_id !== $groupId) {
            $updates['car_group_id'] = $groupId;
        }

        if ($item->model !== $row->model) {
            $updates['model'] = $row->model;
        }

        if ($item->serial_code !== $row->serialCode) {
            $updates['serial_code'] = $row->serialCode;
            $updates['normalized_serial'] = $row->normalizedSerial;
        }

        if (filled($row->details) && $item->details !== $row->details) {
            $updates['details'] = $row->details;
        }

        if (filled($row->shapeCode) && $item->shape_code !== $row->shapeCode) {
            $updates['shape_code'] = $row->shapeCode;
        }

        if ($item->source !== 'excel_import') {
            $updates['source'] = 'excel_import';
        }

        return $updates;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPetraRow(Worksheet $sheet, int $rowIndex): array
    {
        return [
            'serial_code' => $this->cleanString($sheet->getCellByColumnAndRow(1, $rowIndex)->getValue()),
            'details' => $this->cleanString($sheet->getCellByColumnAndRow(2, $rowIndex)->getValue()),
            'model' => $this->cleanString($sheet->getCellByColumnAndRow(3, $rowIndex)->getValue()),
            'weight_kg' => $this->readDecimalColumn($sheet, 4, $rowIndex, 8, 3),
            'pt_ppm' => $this->readDecimalColumn($sheet, 5, $rowIndex, 10, 4),
            'pd_ppm' => $this->readDecimalColumn($sheet, 6, $rowIndex, 10, 4),
            'rh_ppm' => $this->readDecimalColumn($sheet, 7, $rowIndex, 10, 4),
        ];
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    private function isPotentialPetraRow(array $mapped): bool
    {
        return filled($mapped['serial_code'])
            || filled($mapped['model'])
            || filled($mapped['weight_kg'])
            || filled($mapped['pt_ppm'])
            || filled($mapped['pd_ppm'])
            || filled($mapped['rh_ppm'])
            || filled($mapped['details']);
    }

    private function shouldSkipLegacySheet(Worksheet $sheet): bool
    {
        $normalizedTitle = Str::lower(trim($sheet->getTitle()));
        $normalizedTitle = preg_replace('/\s+/u', '', $normalizedTitle) ?? $normalizedTitle;

        if (preg_match('/^Ð»Ð¸ÑÑ‚\d*$/u', $normalizedTitle) === 1) {
            return true;
        }

        return ! $this->hasAnyCellData($sheet);
    }

    private function hasAnyCellData(Worksheet $sheet): bool
    {
        $maxRow = min(20, $sheet->getHighestDataRow());
        $maxCol = min(20, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));

        for ($row = 1; $row <= $maxRow; $row++) {
            for ($col = 1; $col <= $maxCol; $col++) {
                $value = $sheet->getCellByColumnAndRow($col, $row)->getValue();

                if ($value === null) {
                    continue;
                }

                if (is_string($value) && trim($value) === '') {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *   start_row: int,
     *   model: int,
     *   serial: int,
     *   weight: int,
     *   pt: int,
     *   pd: int,
     *   rh: int,
     *   extra_codes: int,
     *   details: int,
     *   shape_code: int
     * }
     */
    private function detectLegacyLayout(Worksheet $sheet): array
    {
        $highestCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $highestRow = $sheet->getHighestDataRow();
        $scanRows = min(3, $highestRow);

        $bestRow = 1;
        $bestScore = 0;

        for ($row = 1; $row <= $scanRows; $row++) {
            $score = 0;

            for ($col = 1; $col <= min(25, $highestCol); $col++) {
                $header = $this->normalizeHeader($sheet->getCellByColumnAndRow($col, $row)->getValue());

                if ($header === '') {
                    continue;
                }

                $role = $this->inferLegacyHeaderRole($header);
                if ($role === null) {
                    continue;
                }

                $score += in_array($role, ['serial', 'model'], true) ? 2 : 1;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRow = $row;
            }
        }

        if ($bestScore === 0) {
            return [
                'start_row' => 4,
                'model' => 1,
                'serial' => 2,
                'weight' => 3,
                'pt' => 4,
                'pd' => 6,
                'rh' => 8,
                'extra_codes' => 11,
                'details' => 13,
                'shape_code' => 17,
            ];
        }

        $mapped = [
            'model' => null,
            'serial' => null,
            'weight' => null,
            'pt' => null,
            'pd' => null,
            'rh' => null,
            'extra_codes' => null,
            'details' => null,
            'shape_code' => null,
        ];

        for ($col = 1; $col <= min(25, $highestCol); $col++) {
            $header = $this->normalizeHeader($sheet->getCellByColumnAndRow($col, $bestRow)->getValue());

            if ($header === '') {
                continue;
            }

            $role = $this->inferLegacyHeaderRole($header);
            if ($role === null) {
                continue;
            }

            if ($role === 'serial' && $mapped['serial'] === null) {
                $mapped['serial'] = $col;

                continue;
            }

            if ($role === 'model' && $mapped['model'] === null) {
                $mapped['model'] = $col;

                continue;
            }

            if ($role === 'details' && $mapped['details'] === null) {
                $mapped['details'] = $col;

                continue;
            }

            if ($role === 'weight' && $mapped['weight'] === null) {
                $mapped['weight'] = $col;

                continue;
            }

            if ($role === 'pt' && $mapped['pt'] === null) {
                $mapped['pt'] = $col;

                continue;
            }

            if ($role === 'pd' && $mapped['pd'] === null) {
                $mapped['pd'] = $col;

                continue;
            }

            if ($role === 'rh' && $mapped['rh'] === null) {
                $mapped['rh'] = $col;

                continue;
            }
        }

        return [
            'start_row' => $bestRow + 1,
            'model' => (int) ($mapped['model'] ?? 0),
            'serial' => (int) ($mapped['serial'] ?? 2),
            'weight' => (int) ($mapped['weight'] ?? 3),
            'pt' => (int) ($mapped['pt'] ?? 4),
            'pd' => (int) ($mapped['pd'] ?? 6),
            'rh' => (int) ($mapped['rh'] ?? 8),
            'extra_codes' => (int) ($mapped['extra_codes'] ?? 0),
            'details' => (int) ($mapped['details'] ?? 0),
            'shape_code' => (int) ($mapped['shape_code'] ?? 0),
        ];
    }

    private function inferLegacyHeaderRole(string $header): ?string
    {
        if ($this->matchesAny($header, [
            'Ð·Ð°Ð²',
            'ÐºÐ°Ñ‚Ð°Ð»',
            'ÐºÐ°Ñ‚Ð°Ð»Ð¾Ð¶ÐµÐ½',
            'converterrefno',
            'serial',
            'refno',
            'Ã°Â·Ã°Â°Ã°Â²',
            'Ã°ÂºÃ°Â°Ã±â€šÃ°Â°Ã°Â»',
        ])) {
            return 'serial';
        }

        if ($this->matchesAny($header, [
            'Ð¿Ñ€Ð¾Ð¸Ð·Ð²',
            'Ð¼Ð°Ñ€ÐºÐ°',
            'manufacturername',
            'manufacturer',
            'brand',
            'Ã°Â¿Ã±â‚¬Ã°Â¾Ã°Â¸Ã°Â·Ã°Â²',
            'Ã°Â¼Ã°Â°Ã±â‚¬Ã°ÂºÃ°Â°',
        ])) {
            return 'model';
        }

        if ($this->matchesAny($header, [
            'Ð´Ð¾Ð¿',
            'Ð¸Ð½Ñ„',
            'additionaldescription',
            'additionalinfo',
            'description',
            'Ã°Â´Ã°Â¾Ã°Â¿',
        ])) {
            return 'details';
        }

        if ($this->matchesAny($header, [
            'Ñ‚ÐµÐ³Ð»Ð¾',
            'weightofcarrier',
            'weight',
            'Ã±â€šÃ°ÂµÃ°Â³Ã°Â»Ã°Â¾',
        ])) {
            return 'weight';
        }

        if (str_contains($header, 'pt')) {
            return 'pt';
        }

        if (str_contains($header, 'pd')) {
            return 'pd';
        }

        if (str_contains($header, 'rh')) {
            return 'rh';
        }

        return null;
    }

    private function matchesAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, int>  $layout
     * @return array<string, mixed>
     */
    private function mapLegacyRow(Worksheet $sheet, int $rowIndex, array $layout, string $fallbackModel): array
    {
        $model = null;

        if (($layout['model'] ?? 0) > 0) {
            $model = $this->cleanString($sheet->getCellByColumnAndRow($layout['model'], $rowIndex)->getValue());
        }

        $model = $model ?? $fallbackModel;

        $readOptional = function (int $column) use ($sheet, $rowIndex): ?string {
            if ($column <= 0) {
                return null;
            }

            return $this->cleanString($sheet->getCellByColumnAndRow($column, $rowIndex)->getValue());
        };

        return [
            'model' => $model,
            'serial_code' => $this->readStringColumn($sheet, (int) ($layout['serial'] ?? 0), $rowIndex),
            'weight_kg' => $this->readDecimalColumn($sheet, (int) ($layout['weight'] ?? 0), $rowIndex, 8, 3),
            'pt_ppm' => $this->readDecimalColumn($sheet, (int) ($layout['pt'] ?? 0), $rowIndex, 10, 4),
            'pd_ppm' => $this->readDecimalColumn($sheet, (int) ($layout['pd'] ?? 0), $rowIndex, 10, 4),
            'rh_ppm' => $this->readDecimalColumn($sheet, (int) ($layout['rh'] ?? 0), $rowIndex, 10, 4),
            'extra_codes' => $readOptional((int) ($layout['extra_codes'] ?? 0)),
            'details' => $readOptional((int) ($layout['details'] ?? 0)),
            'shape_code' => $this->readShapeCodeColumn($sheet, (int) ($layout['shape_code'] ?? 0), $rowIndex),
        ];
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    private function isPotentialLegacyRow(array $mapped): bool
    {
        return filled($mapped['serial_code'])
            || filled($mapped['weight_kg'])
            || filled($mapped['pt_ppm'])
            || filled($mapped['pd_ppm'])
            || filled($mapped['rh_ppm'])
            || filled($mapped['details'])
            || filled($mapped['extra_codes'])
            || filled($mapped['shape_code']);
    }

    /**
     * @param  array<string, int>  $layout
     * @param  array<string, mixed>  $mapped
     */
    private function isInvalidLegacyRow(Worksheet $sheet, int $rowIndex, array $layout, array $mapped): bool
    {
        if (blank($mapped['serial_code'])) {
            return true;
        }

        if ($this->hasAmbiguousAssayValue($sheet, $rowIndex, $layout, $mapped)) {
            return true;
        }

        if (blank($mapped['model'])) {
            return true;
        }

        return ! $this->hasEnrichmentValues($mapped);
    }

    /**
     * @param  array<string, int>  $layout
     * @param  array<string, mixed>  $mapped
     */
    private function hasAmbiguousAssayValue(Worksheet $sheet, int $rowIndex, array $layout, array $mapped): bool
    {
        foreach (['pt' => 'pt_ppm', 'pd' => 'pd_ppm', 'rh' => 'rh_ppm'] as $layoutKey => $mappedKey) {
            $column = (int) ($layout[$layoutKey] ?? 0);

            if ($column <= 0) {
                continue;
            }

            $raw = $sheet->getCellByColumnAndRow($column, $rowIndex)->getValue();
            $rawString = trim((string) $raw);

            if ($rawString === '' || str_starts_with($rawString, '=')) {
                continue;
            }

            if ($mapped[$mappedKey] !== null) {
                continue;
            }

            if (
                str_contains($rawString, '/')
                || str_contains($rawString, ';')
                || str_contains($rawString, '|')
                || preg_match('/[\p{L}]/u', $rawString) === 1
                || preg_match('/\d+\s*-\s*\d+/u', $rawString) === 1
            ) {
                return true;
            }

            $candidate = str_replace([' ', "'", ','], ['', '', '.'], $rawString);

            if (! is_numeric($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    private function hasEnrichmentValues(array $mapped): bool
    {
        return filled($mapped['weight_kg'])
            || filled($mapped['pt_ppm'])
            || filled($mapped['pd_ppm'])
            || filled($mapped['rh_ppm'])
            || filled($mapped['shape_code'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function missingFieldUpdates(Item $item, ExcelItemRowData $row): array
    {
        $updates = [];

        foreach (['weight_kg' => $row->weightKg, 'pt_ppm' => $row->ptPpm, 'pd_ppm' => $row->pdPpm, 'rh_ppm' => $row->rhPpm] as $field => $value) {
            if ($item->{$field} === null && $value !== null) {
                $updates[$field] = $value;
            }
        }

        if (blank($item->shape_code) && filled($row->shapeCode)) {
            $updates['shape_code'] = $row->shapeCode;
        }

        return $updates;
    }

    private function resolveGroup(string $canonicalGroupName): CarGroup
    {
        if (isset($this->groupCache[$canonicalGroupName])) {
            return $this->groupCache[$canonicalGroupName];
        }

        $group = $this->groupResolver->resolve($canonicalGroupName, true);

        if (! $group instanceof CarGroup) {
            throw new RuntimeException('Failed to resolve car group: '.$canonicalGroupName);
        }

        if ($group->wasRecentlyCreated) {
            $group->source = 'excel_import';
            $group->save();
        }

        $this->groupCache[$canonicalGroupName] = $group;
        $this->existingGroupCache[$canonicalGroupName] = $group;

        return $group;
    }

    private function findExistingGroup(string $canonicalGroupName): ?CarGroup
    {
        if (array_key_exists($canonicalGroupName, $this->existingGroupCache)) {
            return $this->existingGroupCache[$canonicalGroupName];
        }

        $group = $this->groupResolver->resolve($canonicalGroupName, false);

        $this->existingGroupCache[$canonicalGroupName] = $group;

        if ($group instanceof CarGroup) {
            $this->groupCache[$canonicalGroupName] = $group;
        }

        return $group;
    }

    /**
     * @return Collection<int, Item>
     */
    private function existingSameSerial(string $groupId, string $normalizedSerial): Collection
    {
        $cacheKey = $groupId.'|'.$normalizedSerial;

        if (! array_key_exists($cacheKey, $this->serialItemCache)) {
            $this->serialItemCache[$cacheKey] = Item::query()
                ->where('car_group_id', $groupId)
                ->where(function ($query) use ($normalizedSerial): void {
                    $query->where('normalized_serial', $normalizedSerial)
                        ->orWhereRaw($this->normalizedSerialSql().' = ?', [$normalizedSerial]);
                })
                ->orderByDesc('created_at')
                ->get();
        }

        return $this->serialItemCache[$cacheKey];
    }

    private function appendItemCache(string $groupId, string $normalizedSerial, Item $item): void
    {
        $cacheKey = $groupId.'|'.$normalizedSerial;

        if (! isset($this->serialItemCache[$cacheKey])) {
            $this->serialItemCache[$cacheKey] = collect([$item]);

            return;
        }

        $this->serialItemCache[$cacheKey]->prepend($item);
    }

    private function canonicalGroupName(string $value): string
    {
        $normalized = $this->groupResolver->normalizeSheetName($value);

        return $this->groupResolver->canonicalSheetName($normalized);
    }

    private function decimal(?float $value, int $precision): string
    {
        if ($value === null) {
            return 'null';
        }

        return number_format($value, $precision, '.', '');
    }

    private function normalizeHeader(mixed $value): string
    {
        $header = Str::lower(trim((string) $value));

        return preg_replace('/\s+/u', '', $header) ?? $header;
    }

    private function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        if ($string === '' || str_starts_with($string, '=')) {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', $string);

        return $collapsed === '' ? null : $collapsed;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && str_starts_with($value, '=')) {
            return null;
        }

        $cleaned = trim((string) $value);

        if ($cleaned === '') {
            return null;
        }

        $cleaned = str_replace([' ', "'", ','], ['', '', '.'], $cleaned);

        if (! is_numeric($cleaned)) {
            return null;
        }

        $floatValue = (float) $cleaned;

        return is_finite($floatValue) ? $floatValue : null;
    }

    private function readDecimalColumn(Worksheet $sheet, int $column, int $rowIndex, int $precision, int $scale): ?float
    {
        if ($column <= 0) {
            return null;
        }

        return $this->sanitizeDecimalValue(
            $this->toFloat($sheet->getCellByColumnAndRow($column, $rowIndex)->getValue()),
            $precision,
            $scale
        );
    }

    private function sanitizeDecimalValue(?float $value, int $precision, int $scale): ?float
    {
        if ($value === null || ! is_finite($value) || $value < 0) {
            return null;
        }

        $rounded = round($value, $scale, PHP_ROUND_HALF_UP);
        $max = (10 ** ($precision - $scale)) - (10 ** (-$scale));

        return $rounded <= $max ? $rounded : null;
    }

    private function readShapeCodeColumn(Worksheet $sheet, int $column, int $rowIndex): ?string
    {
        if ($column <= 0) {
            return null;
        }

        $value = $this->cleanString($sheet->getCellByColumnAndRow($column, $rowIndex)->getValue());

        if ($value === null || mb_strlen($value) > 20) {
            return null;
        }

        return $value;
    }

    private function readStringColumn(Worksheet $sheet, int $column, int $rowIndex): ?string
    {
        if ($column <= 0) {
            return null;
        }

        return $this->cleanString($sheet->getCellByColumnAndRow($column, $rowIndex)->getValue());
    }

    private function readFloatColumn(Worksheet $sheet, int $column, int $rowIndex): ?float
    {
        if ($column <= 0) {
            return null;
        }

        return $this->toFloat($sheet->getCellByColumnAndRow($column, $rowIndex)->getValue());
    }

    /**
     * @param  array<int, array<string, int|string>>  $files
     * @return array<string, int>
     */
    private function sumReports(array $files): array
    {
        $totals = $this->numericReportKeys();
        $summary = array_fill_keys($totals, 0);

        foreach ($files as $report) {
            foreach ($totals as $key) {
                $summary[$key] += (int) ($report[$key] ?? 0);
            }
        }

        return $summary;
    }

    /**
     * @return array<string, int|string>
     */
    private function makeEmptyReport(string $path): array
    {
        return [
            'path' => $path,
            'rows_scanned' => 0,
            'rows_updated' => 0,
            'rows_created' => 0,
            'groups_created' => 0,
            'rows_invalid' => 0,
            'rows_skipped_noop' => 0,
            'rows_skipped_ambiguous' => 0,
            'rows_skipped_not_found' => 0,
            'rows_skipped_duplicate_in_file' => 0,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function numericReportKeys(): array
    {
        return [
            'rows_scanned',
            'rows_updated',
            'rows_created',
            'groups_created',
            'rows_invalid',
            'rows_skipped_noop',
            'rows_skipped_ambiguous',
            'rows_skipped_not_found',
            'rows_skipped_duplicate_in_file',
        ];
    }

    private function normalizedSerialSql(): string
    {
        return "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(serial_code, ''), ' ', ''), '-', ''), '/', ''), '.', ''))";
    }

    private function resetRunState(): void
    {
        $this->seenSignatures = [];
        $this->groupCache = [];
        $this->existingGroupCache = [];
        $this->serialItemCache = [];
        $this->repairItemCache = [];
    }

    /**
     * @return array{
     *   files: array<int, array<string, int|string>>,
     *   totals: array<string, int>
     * }
     */
    private function runDry(callable $callback): array
    {
        DB::beginTransaction();

        try {
            $result = $callback();
            DB::rollBack();

            return $result;
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }
}
