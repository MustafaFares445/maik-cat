<?php

namespace App\Services;

use App\Models\ImportBatch;
use App\Models\ImportRowIssue;
use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

class EnhancedLegacyWorkbookImportService
{
    private const ISSUE_MISSING_SERIAL = 'missing_serial_code';

    private const ISSUE_INVALID_SERIAL = 'invalid_serial_code';

    private const ISSUE_CONTROL_ROW = 'control_row';

    private const ISSUE_MISSING_MODEL = 'missing_model';

    private const ISSUE_INVALID_WEIGHT = 'missing_or_invalid_weight';

    private const ISSUE_MISSING_ASSAY = 'missing_assay_values';

    private const ISSUE_AMBIGUOUS_ASSAY = 'ambiguous_assay_value';

    private int $inserted = 0;

    private int $skipped = 0;

    private int $invalid = 0;

    private int $flagged = 0;

    /** @var array<string, true> */
    private array $seenSignatures = [];

    /** @var array<string, Collection<int, Item>> */
    private array $serialItemCache = [];

    public function __construct(
        private readonly ImportSheetGroupResolver $groupResolver,
        private readonly ItemSiblingMediaCopier $mediaCopier,
    ) {}

    /**
     * @return array{rows_inserted:int,rows_skipped:int,rows_invalid:int,rows_flagged:int}
     */
    public function import(ImportBatch $batch, string $filePath, bool $dryRun = false): array
    {
        if (! is_file($filePath)) {
            throw new RuntimeException('Import source file does not exist.');
        }

        $this->resetState();

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        try {
            foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
                if ($this->shouldSkipSheet($sheet)) {
                    continue;
                }

                $layout = $this->detectLayout($sheet);
                $canonicalSheetName = $this->groupResolver->canonicalSheetName(
                    $this->groupResolver->normalizeSheetName($sheet->getTitle()),
                );
                $group = $this->groupResolver->resolve($sheet->getTitle(), ! $dryRun);
                $groupId = $group?->id;
                $groupKey = $groupId ?? 'virtual:'.$canonicalSheetName;
                $fallbackModel = $group?->name ?? $canonicalSheetName;
                $highestRow = $sheet->getHighestDataRow();

                for ($rowIndex = $layout['start_row']; $rowIndex <= $highestRow; $rowIndex++) {
                    $mapped = $this->mapRow($sheet, $rowIndex, $layout, $fallbackModel);

                    if (! $this->isPotentialDataRow($mapped)) {
                        continue;
                    }

                    $issue = $this->determineInvalidIssue($sheet, $rowIndex, $layout, $mapped);

                    if ($issue !== null) {
                        if (! $dryRun) {
                            $this->recordRowIssue($batch, $sheet, $rowIndex, $layout, $mapped, $issue);
                        }

                        $this->invalid++;
                        continue;
                    }

                    $normalizedSerial = Item::normalizeSerialValue($mapped['serial_code']);
                    $signature = $this->signature($groupKey, $normalizedSerial, $mapped);

                    if (isset($this->seenSignatures[$signature])) {
                        $this->skipped++;
                        continue;
                    }

                    $this->seenSignatures[$signature] = true;
                    $existingSameSerial = $this->existingSameSerial($groupId, $normalizedSerial);

                    if ($this->hasExactAssayMatch($existingSameSerial, $mapped)) {
                        $this->skipped++;
                        continue;
                    }

                    if (! $dryRun) {
                        $item = $this->insertItem((string) $groupId, $mapped);
                        $this->appendCache((string) $groupId, $normalizedSerial, $item);
                        $this->copySiblingImage($item);
                    }

                    $this->inserted++;
                }
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return [
            'rows_inserted' => $this->inserted,
            'rows_skipped' => $this->skipped,
            'rows_invalid' => $this->invalid,
            'rows_flagged' => $this->flagged,
        ];
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
        $title = Str::lower(trim($sheet->getTitle()));
        $compactTitle = preg_replace('/\s+/u', '', $title) ?? $title;

        if ($compactTitle === 'kitko' || preg_match('/^лист\d*$/u', $compactTitle) === 1) {
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

                if ($value !== null && (! is_string($value) || trim($value) !== '')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{start_row:int,model:int,serial:int,weight:int,pt:int,pd:int,rh:int,extra_codes:int,details:int,shape_code:int}
     */
    private function detectLayout(Worksheet $sheet): array
    {
        $highestCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $highestRow = $sheet->getHighestDataRow();
        $scanRows = min(3, $highestRow);
        $bestRow = 1;
        $bestScore = 0;

        for ($row = 1; $row <= $scanRows; $row++) {
            $score = 0;

            for ($col = 1; $col <= min(25, $highestCol); $col++) {
                $role = $this->inferHeaderRole(
                    $this->normalizeHeader($sheet->getCellByColumnAndRow($col, $row)->getValue()),
                );

                $score += match ($role) {
                    'serial', 'model' => 5,
                    'weight' => 3,
                    'extra_codes', 'details', 'shape_code' => 2,
                    'pt', 'pd', 'rh' => 1,
                    default => 0,
                };
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRow = $row;
            }
        }

        if ($bestScore === 0) {
            return $this->fallbackLayout();
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
            $role = $this->inferHeaderRole(
                $this->normalizeHeader($sheet->getCellByColumnAndRow($col, $bestRow)->getValue()),
            );

            if ($role !== null && array_key_exists($role, $mapped) && $mapped[$role] === null) {
                $mapped[$role] = $col;
            }
        }

        return [
            'start_row' => $bestRow + 1,
            'model' => (int) ($mapped['model'] ?? 1),
            'serial' => (int) ($mapped['serial'] ?? 2),
            'weight' => (int) ($mapped['weight'] ?? 3),
            'pt' => (int) ($mapped['pt'] ?? 4),
            'pd' => (int) ($mapped['pd'] ?? 6),
            'rh' => (int) ($mapped['rh'] ?? 8),
            'extra_codes' => (int) ($mapped['extra_codes'] ?? 11),
            'details' => (int) ($mapped['details'] ?? 13),
            'shape_code' => (int) ($mapped['shape_code'] ?? 17),
        ];
    }

    /**
     * @return array{start_row:int,model:int,serial:int,weight:int,pt:int,pd:int,rh:int,extra_codes:int,details:int,shape_code:int}
     */
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

    private function inferHeaderRole(string $header): ?string
    {
        if ($header === '') {
            return null;
        }

        if ($this->matchesAny($header, ['serialcode', 'serial', 'converterrefno', 'refno', 'зав', 'катал'])) {
            return 'serial';
        }

        if ($this->matchesAny($header, ['model', 'manufacturername', 'manufacturer', 'brand', 'произв', 'марка'])) {
            return 'model';
        }

        if ($this->matchesAny($header, ['piecekg', 'weightofcarrier', 'weight', 'тегло'])) {
            return 'weight';
        }

        if ($this->matchesAny($header, ['extracodes', 'additionalcodes', 'alternativecodes'])) {
            return 'extra_codes';
        }

        if ($this->matchesAny($header, ['details', 'additionaldescription', 'additionalinfo', 'description', 'доп', 'инф'])) {
            return 'details';
        }

        if ($this->matchesAny($header, ['shapecode', 'shape', 'formcode'])) {
            return 'shape_code';
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
     * @param array<string,int> $layout
     * @return array<string,mixed>
     */
    private function mapRow(Worksheet $sheet, int $rowIndex, array $layout, string $fallbackModel): array
    {
        $model = $this->readStringColumn($sheet, $layout['model'], $rowIndex) ?? $fallbackModel;

        return [
            'model' => $model,
            'serial_code' => $this->readStringColumn($sheet, $layout['serial'], $rowIndex),
            'weight_kg' => $this->readFloatColumn($sheet, $layout['weight'], $rowIndex),
            'pt_ppm' => $this->readFloatColumn($sheet, $layout['pt'], $rowIndex),
            'pd_ppm' => $this->readFloatColumn($sheet, $layout['pd'], $rowIndex),
            'rh_ppm' => $this->readFloatColumn($sheet, $layout['rh'], $rowIndex),
            'extra_codes' => $this->readStringColumn($sheet, $layout['extra_codes'], $rowIndex),
            'details' => $this->readStringColumn($sheet, $layout['details'], $rowIndex),
            'shape_code' => $this->readStringColumn($sheet, $layout['shape_code'], $rowIndex),
        ];
    }

    /** @param array<string,mixed> $data */
    private function isPotentialDataRow(array $data): bool
    {
        return filled($data['serial_code'])
            || filled($data['weight_kg'])
            || filled($data['pt_ppm'])
            || filled($data['pd_ppm'])
            || filled($data['rh_ppm'])
            || filled($data['details'])
            || filled($data['extra_codes'])
            || filled($data['shape_code']);
    }

    /**
     * @param array<string,int> $layout
     * @param array<string,mixed> $mapped
     */
    private function determineInvalidIssue(
        Worksheet $sheet,
        int $rowIndex,
        array $layout,
        array $mapped,
    ): ?string {
        if (blank($mapped['serial_code'])) {
            return self::ISSUE_MISSING_SERIAL;
        }

        if ($this->isControlRow($mapped)) {
            return self::ISSUE_CONTROL_ROW;
        }

        if ($this->isInvalidSerial((string) $mapped['serial_code'])) {
            return self::ISSUE_INVALID_SERIAL;
        }

        if (blank($mapped['model'])) {
            return self::ISSUE_MISSING_MODEL;
        }

        if ($mapped['weight_kg'] === null || (float) $mapped['weight_kg'] <= 0.0) {
            return self::ISSUE_INVALID_WEIGHT;
        }

        if ($this->hasAmbiguousAssayValue($sheet, $rowIndex, $layout, $mapped)) {
            return self::ISSUE_AMBIGUOUS_ASSAY;
        }

        if (! $this->hasPositiveAssay($mapped)) {
            return self::ISSUE_MISSING_ASSAY;
        }

        return null;
    }

    /** @param array<string,mixed> $mapped */
    private function isControlRow(array $mapped): bool
    {
        $serial = Str::upper(trim((string) ($mapped['serial_code'] ?? '')));
        $model = Str::upper(trim((string) ($mapped['model'] ?? '')));

        return str_contains($serial, 'KONTROLINIS') || str_contains($model, 'KONTROLINIS');
    }

    private function isInvalidSerial(string $serial): bool
    {
        $serial = trim($serial);

        return $serial === ''
            || preg_match('/^[\?\.\-\_\/\\]+$/u', $serial) === 1
            || in_array(Str::upper($serial), ['UNKNOWN', 'N/A', 'NA', 'NONE', 'NULL'], true);
    }

    /** @param array<string,mixed> $mapped */
    private function hasPositiveAssay(array $mapped): bool
    {
        return (float) ($mapped['pt_ppm'] ?? 0) > 0.0
            || (float) ($mapped['pd_ppm'] ?? 0) > 0.0
            || (float) ($mapped['rh_ppm'] ?? 0) > 0.0;
    }

    /**
     * @param array<string,int> $layout
     * @param array<string,mixed> $mapped
     */
    private function hasAmbiguousAssayValue(Worksheet $sheet, int $rowIndex, array $layout, array $mapped): bool
    {
        foreach (['pt' => 'pt_ppm', 'pd' => 'pd_ppm', 'rh' => 'rh_ppm'] as $layoutKey => $mappedKey) {
            $raw = $sheet->getCellByColumnAndRow($layout[$layoutKey], $rowIndex)->getValue();
            $rawString = trim((string) $raw);

            if ($rawString === '' || str_starts_with($rawString, '=') || str_starts_with($rawString, '#')) {
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

    /** @param array<string,mixed> $data */
    private function insertItem(string $groupId, array $data): Item
    {
        $item = Item::query()->create([
            'id' => (string) Str::uuid(),
            'car_group_id' => $groupId,
            'model' => $data['model'],
            'serial_code' => $data['serial_code'],
            'normalized_serial' => Item::normalizeSerialValue($data['serial_code']),
            'weight_kg' => $data['weight_kg'],
            'pt_ppm' => $data['pt_ppm'],
            'pd_ppm' => $data['pd_ppm'],
            'rh_ppm' => $data['rh_ppm'],
            'details' => $data['details'],
            'shape_code' => $data['shape_code'],
            'source' => 'excel_import',
        ]);

        $this->insertExtraCodes($item, $data['extra_codes'] ?? null);

        return $item;
    }

    private function insertExtraCodes(Item $item, ?string $raw): void
    {
        if (blank($raw)) {
            return;
        }

        collect(preg_split('/[\/;,|]+/', $raw) ?: [])
            ->map(static fn (string $code): string => trim($code))
            ->filter()
            ->unique(static fn (string $code): string => Str::upper($code))
            ->each(fn (string $code) => $item->extraCodes()->create([
                'id' => (string) Str::uuid(),
                'code' => $code,
                'source' => 'excel_import',
            ]));
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

    private function appendCache(string $groupId, string $normalizedSerial, Item $item): void
    {
        $cacheKey = $groupId.'|'.$normalizedSerial;

        if (! isset($this->serialItemCache[$cacheKey])) {
            $this->serialItemCache[$cacheKey] = collect([$item]);
            return;
        }

        $this->serialItemCache[$cacheKey]->prepend($item);
    }

    /**
     * @param Collection<int,Item> $items
     * @param array<string,mixed> $data
     */
    private function hasExactAssayMatch(Collection $items, array $data): bool
    {
        $target = $this->assayTuple($data);

        foreach ($items as $item) {
            if ($this->assayTuple([
                'weight_kg' => $item->weight_kg,
                'pt_ppm' => $item->pt_ppm,
                'pd_ppm' => $item->pd_ppm,
                'rh_ppm' => $item->rh_ppm,
            ]) === $target) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $data */
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

    private function decimal(mixed $value, int $precision): string
    {
        return $value === null ? 'null' : number_format((float) $value, $precision, '.', '');
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

    private function normalizeHeader(mixed $value): string
    {
        $header = Str::lower(trim((string) $value));

        return preg_replace('/[^\pL\pN]+/u', '', $header) ?? $header;
    }

    private function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        if ($string === '' || str_starts_with($string, '=') || str_starts_with($string, '#')) {
            return null;
        }

        return preg_replace('/\s+/u', ' ', $string) ?: $string;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = trim((string) $value);

        if ($raw === '' || str_starts_with($raw, '=') || str_starts_with($raw, '#')) {
            return null;
        }

        $cleaned = str_replace([' ', "'", ','], ['', '', '.'], $raw);

        return is_numeric($cleaned) ? (float) $cleaned : null;
    }

    /**
     * @param array<string,int> $layout
     * @param array<string,mixed> $mapped
     */
    private function recordRowIssue(
        ImportBatch $batch,
        Worksheet $sheet,
        int $rowIndex,
        array $layout,
        array $mapped,
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
            'normalized_payload' => $mapped,
        ]);
    }

    private function rawCell(Worksheet $sheet, int $column, int $rowIndex): mixed
    {
        return $column > 0 ? $sheet->getCellByColumnAndRow($column, $rowIndex)->getValue() : null;
    }

    private function normalizedSerialSql(): string
    {
        return "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(serial_code, ''), ' ', ''), '-', ''), '/', ''), '.', ''))";
    }
}
