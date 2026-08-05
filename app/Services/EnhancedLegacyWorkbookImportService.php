<?php

namespace App\Services;

use App\Models\ImportBatch;
use App\Models\ImportRowIssue;
use App\Models\Item;
use App\Support\Excel\WindowedWorkbook;
use App\Support\Excel\WindowReadFilter;
use App\Support\Items\CatalystSerialValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

class EnhancedLegacyWorkbookImportService
{
    private const ROW_CHUNK_SIZE = 500;

    private int $inserted = 0;

    private int $skipped = 0;

    private int $invalid = 0;

    private int $flagged = 0;

    /** @var array<string,true> */
    private array $seenSignatures = [];

    /** @var array<string,Collection<int,Item>> */
    private array $serialItemCache = [];

    public function __construct(
        private readonly ImportSheetGroupResolver $groupResolver,
        private readonly ItemSiblingMediaCopier $mediaCopier,
    ) {}

    /** @return array{rows_inserted:int,rows_skipped:int,rows_invalid:int,rows_flagged:int} */
    public function import(ImportBatch $batch, string $filePath, bool $dryRun = false): array
    {
        if (! is_file($filePath)) {
            throw new RuntimeException('Import source file does not exist.');
        }

        $this->resetState();

        foreach ($this->worksheetInfos($filePath) as $sheetInfo) {
            $sheetName = $this->worksheetName($sheetInfo);

            if ($sheetName === null) {
                continue;
            }

            $totalRows = max(1, (int) ($sheetInfo['totalRows'] ?? 0));
            $previewEnd = min(20, $totalRows);
            [$previewSpreadsheet, $previewSheet] = $this->loadWorksheetWindow(
                $filePath,
                $sheetName,
                1,
                $previewEnd,
                25,
            );

            try {
                if ($this->shouldSkipSheet($previewSheet)) {
                    continue;
                }

                $layout = $this->detectLayout($previewSheet);
                $canonicalGroupName = $this->groupResolver->canonicalSheetName(
                    $this->groupResolver->normalizeSheetName($sheetName),
                );
                $group = $this->groupResolver->resolve($sheetName, ! $dryRun);
                $groupId = $group?->id;
                $groupKey = $groupId ?? 'virtual:'.$canonicalGroupName;
                $fallbackModel = $group?->name ?? $canonicalGroupName;
                $startRow = max((int) $layout['start_row'], 1);

                for ($chunkStart = $startRow; $chunkStart <= $totalRows; $chunkStart += self::ROW_CHUNK_SIZE) {
                    $chunkEnd = min($totalRows, $chunkStart + self::ROW_CHUNK_SIZE - 1);
                    [$spreadsheet, $sheet] = $this->loadWorksheetWindow(
                        $filePath,
                        $sheetName,
                        $chunkStart,
                        $chunkEnd,
                        25,
                    );

                    try {
                        $this->importSheetRows(
                            $batch,
                            $sheet,
                            $dryRun,
                            $layout,
                            $groupId,
                            $groupKey,
                            $fallbackModel,
                            $chunkStart,
                            $chunkEnd,
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

        return [
            'rows_inserted' => $this->inserted,
            'rows_skipped' => $this->skipped,
            'rows_invalid' => $this->invalid,
            'rows_flagged' => $this->flagged,
        ];
    }

    /**
     * @param  array<string,int>  $layout
     */
    private function importSheetRows(
        ImportBatch $batch,
        Worksheet $sheet,
        bool $dryRun,
        array $layout,
        ?string $groupId,
        string $groupKey,
        string $fallbackModel,
        int $startRow,
        int $endRow,
    ): void {
        for ($rowIndex = $startRow; $rowIndex <= $endRow; $rowIndex++) {
            $data = $this->mapRow($sheet, $rowIndex, $layout, $fallbackModel);

            if (! $this->isPotentialDataRow($data)) {
                continue;
            }

            $issue = $this->invalidIssue($sheet, $rowIndex, $layout, $data);

            if ($issue !== null) {
                if (! $dryRun) {
                    $this->recordIssue($batch, $sheet, $rowIndex, $layout, $data, $issue);
                }

                $this->invalid++;

                continue;
            }

            $normalizedSerial = Item::normalizeSerialValue($data['serial_code']);
            $signature = $this->signature($groupKey, $normalizedSerial, $data);

            if (isset($this->seenSignatures[$signature])) {
                $this->skipped++;

                continue;
            }

            $this->seenSignatures[$signature] = true;
            $existing = $this->existingSameSerial($groupId, $normalizedSerial);

            if ($this->hasExactAssay($existing, $data)) {
                $this->skipped++;

                continue;
            }

            if (! $dryRun) {
                $item = $this->createItem((string) $groupId, $data);
                $this->appendCache((string) $groupId, $normalizedSerial, $item);
                $this->copySiblingImage($item);
            }

            $this->inserted++;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function worksheetInfos(string $filePath): array
    {
        return WindowedWorkbook::worksheetInfos($filePath);
    }

    /**
     * @param  array<string,mixed>  $sheetInfo
     */
    private function worksheetName(array $sheetInfo): ?string
    {
        $name = (string) ($sheetInfo['worksheetName'] ?? $sheetInfo['sheetName'] ?? '');

        return trim($name) === '' ? null : $name;
    }

    /**
     * @return array{0:Spreadsheet,1:Worksheet}
     */
    private function loadWorksheetWindow(
        string $filePath,
        string $sheetName,
        int $startRow,
        int $endRow,
        int $maxColumn,
    ): array {
        $reader = WindowedWorkbook::reader($filePath);
        $reader->setReadDataOnly(true);

        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        $reader->setLoadSheetsOnly([$sheetName]);
        $reader->setReadFilter(new WindowReadFilter($startRow, $endRow, $maxColumn));

        $spreadsheet = $reader->load(WindowedWorkbook::path($filePath, $maxColumn, [$sheetName]));
        $sheet = $spreadsheet->getSheetByName($sheetName);

        if (! $sheet instanceof Worksheet) {
            $spreadsheet->disconnectWorksheets();

            throw new RuntimeException('Could not load worksheet: '.$sheetName);
        }

        return [$spreadsheet, $sheet];
    }

    private function resetState(): void
    {
        $this->inserted = 0;
        $this->skipped = 0;
        $this->invalid = 0;
        $this->flagged = 0;
        $this->seenSignatures = [];
        $this->serialItemCache = [];
    }

    private function shouldSkipSheet(Worksheet $sheet): bool
    {
        $title = preg_replace('/\s+/u', '', Str::lower(trim($sheet->getTitle()))) ?? '';

        if ($title === 'kitko' || preg_match('/^лист\d*$/u', $title) === 1) {
            return true;
        }

        $maxRow = min(20, $sheet->getHighestDataRow());
        $maxColumn = min(20, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));

        for ($row = 1; $row <= $maxRow; $row++) {
            for ($column = 1; $column <= $maxColumn; $column++) {
                $value = $sheet->getCellByColumnAndRow($column, $row)->getValue();

                if ($value !== null && (! is_string($value) || trim($value) !== '')) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return array{start_row:int,model:int,serial:int,weight:int,pt:int,pd:int,rh:int,extra_codes:int,details:int,shape_code:int} */
    private function detectLayout(Worksheet $sheet): array
    {
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $bestHeaderRow = 0;
        $bestScore = 0;

        for ($row = 1; $row <= min(3, $sheet->getHighestDataRow()); $row++) {
            $score = 0;

            for ($column = 1; $column <= min(25, $highestColumn); $column++) {
                $role = $this->headerRole($this->normalizeHeader(
                    $sheet->getCellByColumnAndRow($column, $row)->getValue(),
                ));

                $score += match ($role) {
                    'model', 'serial' => 5,
                    'weight' => 3,
                    'extra_codes', 'details', 'shape_code' => 2,
                    'pt', 'pd', 'rh' => 1,
                    default => 0,
                };
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestHeaderRow = $row;
            }
        }

        if ($bestHeaderRow === 0) {
            return $this->fallbackLayout();
        }

        $columns = [
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

        for ($column = 1; $column <= min(25, $highestColumn); $column++) {
            $role = $this->headerRole($this->normalizeHeader(
                $sheet->getCellByColumnAndRow($column, $bestHeaderRow)->getValue(),
            ));

            if ($role !== null && array_key_exists($role, $columns) && $columns[$role] === null) {
                $columns[$role] = $column;
            }
        }

        $fallback = $this->fallbackLayout();

        return [
            'start_row' => $bestHeaderRow + 1,
            'model' => (int) ($columns['model'] ?? $fallback['model']),
            'serial' => (int) ($columns['serial'] ?? $fallback['serial']),
            'weight' => (int) ($columns['weight'] ?? $fallback['weight']),
            'pt' => (int) ($columns['pt'] ?? $fallback['pt']),
            'pd' => (int) ($columns['pd'] ?? $fallback['pd']),
            'rh' => (int) ($columns['rh'] ?? $fallback['rh']),
            'extra_codes' => (int) ($columns['extra_codes'] ?? $fallback['extra_codes']),
            'details' => (int) ($columns['details'] ?? $fallback['details']),
            'shape_code' => (int) ($columns['shape_code'] ?? $fallback['shape_code']),
        ];
    }

    /** @return array{start_row:int,model:int,serial:int,weight:int,pt:int,pd:int,rh:int,extra_codes:int,details:int,shape_code:int} */
    private function fallbackLayout(): array
    {
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

    private function headerRole(string $header): ?string
    {
        if ($header === '') {
            return null;
        }

        $roles = [
            'serial' => ['serialcode', 'serial', 'converterrefno', 'refno', 'зав', 'катал'],
            'model' => ['model', 'manufacturername', 'manufacturer', 'brand', 'произв', 'марка'],
            'weight' => ['piecekg', 'weightofcarrier', 'weight', 'тегло'],
            'extra_codes' => ['extracodes', 'additionalcodes', 'alternativecodes'],
            'details' => ['details', 'additionaldescription', 'additionalinfo', 'description', 'доп', 'инф'],
            'shape_code' => ['shapecode', 'shape', 'formcode'],
        ];

        foreach ($roles as $role => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($header, $needle)) {
                    return $role;
                }
            }
        }

        foreach (['pt', 'pd', 'rh'] as $metal) {
            if (str_contains($header, $metal)) {
                return $metal;
            }
        }

        return null;
    }

    /** @param array<string,int> $layout @return array<string,mixed> */
    private function mapRow(Worksheet $sheet, int $rowIndex, array $layout, string $fallbackModel): array
    {
        return [
            'model' => $this->stringCell($sheet, $layout['model'], $rowIndex) ?? $fallbackModel,
            'serial_code' => $this->stringCell($sheet, $layout['serial'], $rowIndex),
            'weight_kg' => $this->numberCell($sheet, $layout['weight'], $rowIndex),
            'pt_ppm' => $this->numberCell($sheet, $layout['pt'], $rowIndex),
            'pd_ppm' => $this->numberCell($sheet, $layout['pd'], $rowIndex),
            'rh_ppm' => $this->numberCell($sheet, $layout['rh'], $rowIndex),
            'extra_codes' => $this->stringCell($sheet, $layout['extra_codes'], $rowIndex),
            'details' => $this->stringCell($sheet, $layout['details'], $rowIndex),
            'shape_code' => $this->stringCell($sheet, $layout['shape_code'], $rowIndex),
        ];
    }

    /** @param array<string,mixed> $data */
    private function isPotentialDataRow(array $data): bool
    {
        foreach (['serial_code', 'weight_kg', 'pt_ppm', 'pd_ppm', 'rh_ppm', 'extra_codes', 'details', 'shape_code'] as $field) {
            if (filled($data[$field])) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,int> $layout @param array<string,mixed> $data */
    private function invalidIssue(
        Worksheet $sheet,
        int $rowIndex,
        array $layout,
        array $data,
    ): ?string {
        if (blank($data['serial_code'])) {
            return 'missing_serial_code';
        }

        if (! CatalystSerialValidator::isUsable($data['serial_code'])) {
            return str_contains(Str::upper((string) $data['serial_code']), 'KONTROLINIS')
                ? 'control_row'
                : 'invalid_serial_code';
        }

        if (blank($data['model'])) {
            return 'missing_model';
        }

        if ($data['weight_kg'] === null || (float) $data['weight_kg'] <= 0) {
            return 'missing_or_invalid_weight';
        }

        if ($this->hasAmbiguousAssay($sheet, $rowIndex, $layout, $data)) {
            return 'ambiguous_assay_value';
        }

        if (! $this->hasPositiveMetal($data)) {
            return 'missing_assay_values';
        }

        return null;
    }

    /** @param array<string,int> $layout @param array<string,mixed> $data */
    private function hasAmbiguousAssay(Worksheet $sheet, int $rowIndex, array $layout, array $data): bool
    {
        foreach (['pt' => 'pt_ppm', 'pd' => 'pd_ppm', 'rh' => 'rh_ppm'] as $columnKey => $field) {
            $raw = trim((string) $sheet->getCellByColumnAndRow($layout[$columnKey], $rowIndex)->getValue());

            if ($raw === '' || str_starts_with($raw, '=') || str_starts_with($raw, '#') || $data[$field] !== null) {
                continue;
            }

            $normalized = str_replace([' ', "'", ','], ['', '', '.'], $raw);

            if (
                ! is_numeric($normalized)
                || str_contains($raw, '/')
                || str_contains($raw, ';')
                || str_contains($raw, '|')
                || preg_match('/\d+\s*-\s*\d+/u', $raw) === 1
                || preg_match('/[\p{L}]/u', $raw) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $data */
    private function hasPositiveMetal(array $data): bool
    {
        return (float) ($data['pt_ppm'] ?? 0) > 0
            || (float) ($data['pd_ppm'] ?? 0) > 0
            || (float) ($data['rh_ppm'] ?? 0) > 0;
    }

    /** @param array<string,mixed> $data */
    private function createItem(string $groupId, array $data): Item
    {
        $item = Item::query()->create([
            'id' => (string) Str::uuid(),
            'car_group_id' => $groupId,
            'model' => $data['model'],
            'serial_code' => $data['serial_code'],
            'weight_kg' => $data['weight_kg'],
            'pt_ppm' => $data['pt_ppm'],
            'pd_ppm' => $data['pd_ppm'],
            'rh_ppm' => $data['rh_ppm'],
            'details' => $data['details'],
            'shape_code' => $data['shape_code'],
            'source' => 'excel_import',
        ]);

        if (filled($data['extra_codes'])) {
            collect(preg_split('/[\/;,|]+/', (string) $data['extra_codes']) ?: [])
                ->map(static fn (string $code): string => trim($code))
                ->filter()
                ->unique(static fn (string $code): string => Str::upper($code))
                ->each(fn (string $code) => $item->extraCodes()->create([
                    'id' => (string) Str::uuid(),
                    'code' => $code,
                    'source' => 'excel_import',
                ]));
        }

        return $item;
    }

    private function copySiblingImage(Item $item): void
    {
        try {
            $this->mediaCopier->copyFirstImageTo($item);
        } catch (Throwable $exception) {
            report($exception);
            $this->flagged++;
        }
    }

    /** @return Collection<int,Item> */
    private function existingSameSerial(?string $groupId, string $normalizedSerial): Collection
    {
        if (blank($groupId)) {
            return collect();
        }

        $cacheKey = $groupId.'|'.$normalizedSerial;

        return $this->serialItemCache[$cacheKey] ??= Item::query()
            ->where('car_group_id', $groupId)
            ->where(function ($query) use ($normalizedSerial): void {
                $query->where('normalized_serial', $normalizedSerial)
                    ->orWhereRaw($this->normalizedSerialSql().' = ?', [$normalizedSerial]);
            })
            ->orderByDesc('created_at')
            ->get();
    }

    private function appendCache(string $groupId, string $normalizedSerial, Item $item): void
    {
        $cacheKey = $groupId.'|'.$normalizedSerial;
        $this->serialItemCache[$cacheKey] ??= collect();
        $this->serialItemCache[$cacheKey]->prepend($item);
    }

    /** @param Collection<int,Item> $items @param array<string,mixed> $data */
    private function hasExactAssay(Collection $items, array $data): bool
    {
        $target = $this->assayTuple($data);

        return $items->contains(function (Item $item) use ($target): bool {
            return $this->assayTuple([
                'weight_kg' => $item->weight_kg,
                'pt_ppm' => $item->pt_ppm,
                'pd_ppm' => $item->pd_ppm,
                'rh_ppm' => $item->rh_ppm,
            ]) === $target;
        });
    }

    /** @param array<string,mixed> $data @return array<int,string> */
    private function assayTuple(array $data): array
    {
        return [
            $this->decimal($data['weight_kg'] ?? null, 3),
            $this->decimal($data['pt_ppm'] ?? null, 4),
            $this->decimal($data['pd_ppm'] ?? null, 4),
            $this->decimal($data['rh_ppm'] ?? null, 4),
        ];
    }

    /** @param array<string,mixed> $data */
    private function signature(string $groupKey, string $normalizedSerial, array $data): string
    {
        return implode('|', [$groupKey, $normalizedSerial, ...$this->assayTuple($data)]);
    }

    private function stringCell(Worksheet $sheet, int $column, int $row): ?string
    {
        if ($column <= 0) {
            return null;
        }

        $value = $sheet->getCellByColumnAndRow($column, $row)->getValue();

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, '=') || str_starts_with($value, '#')) {
            return null;
        }

        return preg_replace('/\s+/u', ' ', $value) ?: $value;
    }

    private function numberCell(Worksheet $sheet, int $column, int $row): ?float
    {
        $value = $this->stringCell($sheet, $column, $row);

        if ($value === null) {
            return null;
        }

        $value = str_replace([' ', "'", ','], ['', '', '.'], $value);

        return is_numeric($value) ? (float) $value : null;
    }

    private function normalizeHeader(mixed $value): string
    {
        return preg_replace('/[^\pL\pN]+/u', '', Str::lower(trim((string) $value))) ?? '';
    }

    private function decimal(mixed $value, int $precision): string
    {
        return $value === null ? 'null' : number_format((float) $value, $precision, '.', '');
    }

    /** @param array<string,int> $layout @param array<string,mixed> $data */
    private function recordIssue(
        ImportBatch $batch,
        Worksheet $sheet,
        int $rowIndex,
        array $layout,
        array $data,
        string $issueCode,
    ): void {
        ImportRowIssue::query()->create([
            'batch_id' => $batch->id,
            'excel_sheet' => $sheet->getTitle(),
            'excel_row' => $rowIndex,
            'issue_code' => $issueCode,
            'raw_payload' => [
                'model' => $this->rawCell($sheet, $layout['model'], $rowIndex),
                'serial_code' => $this->rawCell($sheet, $layout['serial'], $rowIndex),
                'weight_kg' => $this->rawCell($sheet, $layout['weight'], $rowIndex),
                'pt_ppm' => $this->rawCell($sheet, $layout['pt'], $rowIndex),
                'pd_ppm' => $this->rawCell($sheet, $layout['pd'], $rowIndex),
                'rh_ppm' => $this->rawCell($sheet, $layout['rh'], $rowIndex),
                'extra_codes' => $this->rawCell($sheet, $layout['extra_codes'], $rowIndex),
                'details' => $this->rawCell($sheet, $layout['details'], $rowIndex),
                'shape_code' => $this->rawCell($sheet, $layout['shape_code'], $rowIndex),
            ],
            'normalized_payload' => $data,
        ]);
    }

    private function rawCell(Worksheet $sheet, int $column, int $row): mixed
    {
        return $column > 0 ? $sheet->getCellByColumnAndRow($column, $row)->getValue() : null;
    }

    private function normalizedSerialSql(): string
    {
        return "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(serial_code, ''), ' ', ''), '-', ''), '/', ''), '.', ''))";
    }
}
