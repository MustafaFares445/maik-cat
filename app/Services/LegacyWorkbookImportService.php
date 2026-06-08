<?php

namespace App\Services;

use App\Models\DuplicateReview;
use App\Models\ImportBatch;
use App\Models\ImportRowIssue;
use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class LegacyWorkbookImportService
{
    private const ISSUE_MISSING_SERIAL = 'missing_serial_code';

    private const ISSUE_MISSING_MODEL = 'missing_model';

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

    public function __construct(private readonly ImportSheetGroupResolver $groupResolver) {}

    public function import(ImportBatch $batch, string $filePath, bool $dryRun = false): array
    {
        if (! is_file($filePath)) {
            throw new RuntimeException('Import source file does not exist.');
        }

        $this->inserted = 0;
        $this->skipped = 0;
        $this->invalid = 0;
        $this->flagged = 0;
        $this->seenSignatures = [];
        $this->serialItemCache = [];

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        try {
            foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
                if ($this->shouldSkipSheet($sheet)) {
                    continue;
                }

                $layout = $this->detectLayout($sheet);
                $normalizedSheetName = $this->groupResolver->normalizeSheetName($sheet->getTitle());
                $canonicalSheetName = $this->groupResolver->canonicalSheetName($normalizedSheetName);
                $group = $this->groupResolver->resolve($sheet->getTitle(), ! $dryRun);
                $groupId = $group?->id;
                $groupKey = $groupId ?? ('virtual:'.$canonicalSheetName);
                $fallbackModel = $group?->name ?? $canonicalSheetName;
                $highestRow = $sheet->getHighestDataRow();

                for ($rowIndex = $layout['start_row']; $rowIndex <= $highestRow; $rowIndex++) {
                    $mapped = $this->mapRow($sheet, $rowIndex, $layout, $fallbackModel);

                    if (! $this->isPotentialDataRow($mapped)) {
                        continue;
                    }

                    $invalidIssue = $this->determineInvalidIssue($sheet, $rowIndex, $layout, $mapped);
                    if ($invalidIssue !== null) {
                        $this->recordRowIssue($batch, $sheet, $rowIndex, $layout, $mapped, $invalidIssue);
                        $this->invalid++;

                        continue;
                    }

                    $normalizedSerial = $this->normalizeSerial((string) $mapped['serial_code']);
                    $signature = $this->signature($groupKey, $normalizedSerial, $mapped);

                    if (isset($this->seenSignatures[$signature])) {
                        $this->skipped++;

                        continue;
                    }

                    $existingSameSerial = $this->existingSameSerial($groupId, $normalizedSerial);

                    if ($existingSameSerial->isEmpty()) {
                        if (! $dryRun) {
                            $item = $this->insertItem((string) $groupId, $mapped);
                            $this->appendCache((string) $groupId, $normalizedSerial, $item);
                        }

                        $this->seenSignatures[$signature] = true;
                        $this->inserted++;

                        continue;
                    }

                    if ($this->hasExactAssayMatch($existingSameSerial, $mapped)) {
                        $this->seenSignatures[$signature] = true;
                        $this->skipped++;

                        continue;
                    }

                    if (! $dryRun) {
                        DuplicateReview::query()->create([
                            'batch_id' => $batch->id,
                            'excel_row' => $rowIndex,
                            'excel_sheet' => $sheet->getTitle(),
                            'payload' => [
                                'model' => $mapped['model'],
                                'serial_code' => $mapped['serial_code'],
                                'normalized_serial' => $normalizedSerial,
                                'weight_kg' => $mapped['weight_kg'],
                                'pt_ppm' => $mapped['pt_ppm'],
                                'pd_ppm' => $mapped['pd_ppm'],
                                'rh_ppm' => $mapped['rh_ppm'],
                                'extra_codes' => $mapped['extra_codes'],
                                'details' => $mapped['details'],
                                'shape_code' => $mapped['shape_code'],
                                'match_basis' => 'normalized_serial',
                            ],
                            'existing_item_id' => $existingSameSerial->first()->id,
                            'status' => 'pending',
                        ]);
                    }

                    $this->seenSignatures[$signature] = true;
                    $this->flagged++;
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

    private function shouldSkipSheet(Worksheet $sheet): bool
    {
        $normalizedTitle = Str::lower(trim($sheet->getTitle()));
        $normalizedTitle = preg_replace('/\s+/u', '', $normalizedTitle) ?? $normalizedTitle;

        if (preg_match('/^лист\d*$/u', $normalizedTitle) === 1) {
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
                $header = $this->normalizeHeader($sheet->getCellByColumnAndRow($col, $row)->getValue());

                if ($header === '') {
                    continue;
                }

                $role = $this->inferHeaderRole($header);
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

            $role = $this->inferHeaderRole($header);
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

    private function inferHeaderRole(string $header): ?string
    {
        if ($this->matchesAny($header, [
            'зав',
            'катал',
            'каталожен',
            'converterrefno',
            'serial',
            'refno',
            'ð·ð°ð²',
            'ðºð°ñ‚ð°ð»',
        ])) {
            return 'serial';
        }

        if ($this->matchesAny($header, [
            'произв',
            'марка',
            'manufacturername',
            'manufacturer',
            'brand',
            'ð¿ñ€ð¾ð¸ð·ð²',
            'ð¼ð°ñ€ðºð°',
        ])) {
            return 'model';
        }

        if ($this->matchesAny($header, [
            'доп',
            'инф',
            'additionaldescription',
            'additionalinfo',
            'description',
            'ð´ð¾ð¿',
        ])) {
            return 'details';
        }

        if ($this->matchesAny($header, [
            'тегло',
            'weightofcarrier',
            'weight',
            'ñ‚ðµð³ð»ð¾',
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
     * @return array{
     *   model: string|null,
     *   serial_code: string|null,
     *   weight_kg: float|null,
     *   pt_ppm: float|null,
     *   pd_ppm: float|null,
     *   rh_ppm: float|null,
     *   extra_codes: string|null,
     *   details: string|null,
     *   shape_code: string|null
     * }
     */
    private function mapRow(Worksheet $sheet, int $rowIndex, array $layout, string $fallbackModel): array
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
            'weight_kg' => $this->readFloatColumn($sheet, (int) ($layout['weight'] ?? 0), $rowIndex),
            'pt_ppm' => $this->readFloatColumn($sheet, (int) ($layout['pt'] ?? 0), $rowIndex),
            'pd_ppm' => $this->readFloatColumn($sheet, (int) ($layout['pd'] ?? 0), $rowIndex),
            'rh_ppm' => $this->readFloatColumn($sheet, (int) ($layout['rh'] ?? 0), $rowIndex),
            'extra_codes' => $readOptional((int) ($layout['extra_codes'] ?? 0)),
            'details' => $readOptional((int) ($layout['details'] ?? 0)),
            'shape_code' => $readOptional((int) ($layout['shape_code'] ?? 0)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
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
     * @param  array<string, mixed>  $data
     */
    private function insertItem(string $groupId, array $data): Item
    {
        $item = Item::query()->create([
            'id' => (string) Str::uuid(),
            'car_group_id' => $groupId,
            'model' => $data['model'],
            'serial_code' => $data['serial_code'],
            'normalized_serial' => $this->normalizeSerial((string) $data['serial_code']),
            'weight_kg' => $data['weight_kg'],
            'pt_ppm' => $data['pt_ppm'],
            'pd_ppm' => $data['pd_ppm'],
            'rh_ppm' => $data['rh_ppm'],
            'details' => $data['details'],
            'shape_code' => $data['shape_code'],
        ]);

        $this->insertExtraCodes($item, $data['extra_codes'] ?? null);

        return $item;
    }

    private function insertExtraCodes(Item $item, ?string $raw): void
    {
        if (blank($raw)) {
            return;
        }

        collect(explode('/', $raw))
            ->map(fn (string $code): string => trim($code))
            ->filter()
            ->unique()
            ->each(fn (string $code) => $item->extraCodes()->create([
                'id' => (string) Str::uuid(),
                'code' => $code,
                'source' => 'excel_import',
            ]));
    }

    private function normalizeHeader(mixed $value): string
    {
        $header = Str::lower(trim((string) $value));
        $header = preg_replace('/\s+/u', '', $header) ?? $header;

        return $header;
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

        return is_numeric($cleaned) ? (float) $cleaned : null;
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

    private function normalizeSerial(string $serial): string
    {
        $serial = Str::upper(trim($serial));

        return preg_replace('/[\s\-\.\/]+/u', '', $serial) ?? $serial;
    }

    private function normalizedSerialSql(): string
    {
        return "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(serial_code, ''), ' ', ''), '-', ''), '/', ''), '.', ''))";
    }

    /**
     * @return Collection<int, Item>
     */
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
     * @param  Collection<int, Item>  $items
     * @param  array<string, mixed>  $data
     */
    private function hasExactAssayMatch(Collection $items, array $data): bool
    {
        $target = [
            $this->decimal($data['weight_kg'] ?? null, 3),
            $this->decimal($data['pt_ppm'] ?? null, 4),
            $this->decimal($data['pd_ppm'] ?? null, 4),
            $this->decimal($data['rh_ppm'] ?? null, 4),
        ];

        foreach ($items as $item) {
            $current = [
                $this->decimal($item->weight_kg, 3),
                $this->decimal($item->pt_ppm, 4),
                $this->decimal($item->pd_ppm, 4),
                $this->decimal($item->rh_ppm, 4),
            ];

            if ($current === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function signature(string $groupKey, string $normalizedSerial, array $data): string
    {
        return implode('|', [
            $groupKey,
            $normalizedSerial,
            $this->decimal($data['weight_kg'] ?? null, 3),
            $this->decimal($data['pt_ppm'] ?? null, 4),
            $this->decimal($data['pd_ppm'] ?? null, 4),
            $this->decimal($data['rh_ppm'] ?? null, 4),
        ]);
    }

    private function decimal(?float $value, int $precision): string
    {
        if ($value === null) {
            return 'null';
        }

        return number_format($value, $precision, '.', '');
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @param  array<string, int>  $layout
     */
    private function determineInvalidIssue(Worksheet $sheet, int $rowIndex, array $layout, array $mapped): ?string
    {
        if (blank($mapped['serial_code'])) {
            return self::ISSUE_MISSING_SERIAL;
        }

        if ($this->hasAmbiguousAssayValue($sheet, $rowIndex, $layout, $mapped)) {
            return self::ISSUE_AMBIGUOUS_ASSAY;
        }

        if (blank($mapped['model'])) {
            return self::ISSUE_MISSING_MODEL;
        }

        if (! filled($mapped['pt_ppm']) && ! filled($mapped['pd_ppm']) && ! filled($mapped['rh_ppm'])) {
            return self::ISSUE_MISSING_ASSAY;
        }

        return null;
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
     * @param  array<string, int>  $layout
     * @param  array<string, mixed>  $mapped
     */
    private function recordRowIssue(
        ImportBatch $batch,
        Worksheet $sheet,
        int $rowIndex,
        array $layout,
        array $mapped,
        string $issueCode
    ): void {
        ImportRowIssue::query()->create([
            'batch_id' => $batch->id,
            'excel_sheet' => $sheet->getTitle(),
            'excel_row' => $rowIndex,
            'issue_code' => $issueCode,
            'raw_payload' => $this->extractRawPayload($sheet, $rowIndex, $layout),
            'normalized_payload' => [
                'model' => $mapped['model'],
                'serial_code' => $mapped['serial_code'],
                'weight_kg' => $mapped['weight_kg'],
                'pt_ppm' => $mapped['pt_ppm'],
                'pd_ppm' => $mapped['pd_ppm'],
                'rh_ppm' => $mapped['rh_ppm'],
                'extra_codes' => $mapped['extra_codes'],
                'details' => $mapped['details'],
                'shape_code' => $mapped['shape_code'],
            ],
        ]);
    }

    /**
     * @param  array<string, int>  $layout
     * @return array<string, mixed>
     */
    private function extractRawPayload(Worksheet $sheet, int $rowIndex, array $layout): array
    {
        return [
            'serial_code' => $this->rawCell($sheet, (int) ($layout['serial'] ?? 0), $rowIndex),
            'model' => $this->rawCell($sheet, (int) ($layout['model'] ?? 0), $rowIndex),
            'weight_kg' => $this->rawCell($sheet, (int) ($layout['weight'] ?? 0), $rowIndex),
            'pt_ppm' => $this->rawCell($sheet, (int) ($layout['pt'] ?? 0), $rowIndex),
            'pd_ppm' => $this->rawCell($sheet, (int) ($layout['pd'] ?? 0), $rowIndex),
            'rh_ppm' => $this->rawCell($sheet, (int) ($layout['rh'] ?? 0), $rowIndex),
            'extra_codes' => $this->rawCell($sheet, (int) ($layout['extra_codes'] ?? 0), $rowIndex),
            'details' => $this->rawCell($sheet, (int) ($layout['details'] ?? 0), $rowIndex),
            'shape_code' => $this->rawCell($sheet, (int) ($layout['shape_code'] ?? 0), $rowIndex),
        ];
    }

    private function rawCell(Worksheet $sheet, int $column, int $rowIndex): mixed
    {
        if ($column <= 0) {
            return null;
        }

        return $sheet->getCellByColumnAndRow($column, $rowIndex)->getValue();
    }
}
