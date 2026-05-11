# Excel Import Logic (Detailed)

This document explains how Excel import is implemented in this project, how data flows through the system, and how to handle common data issues for these files:

- `maik 2_101857 (2).xlsx`
- `PETRA UAE CATALOG.xlsx`
- `KIA CATALOG.xlsx`
- `букши.xls`
- `katalog  A&K.xlsx`

Implementation snippets from the codebase are collected in [Section 11: Backend code reference](#11-backend-code-reference).

---

## 1) Entry Points

### API endpoint
- `POST /api/imports` in `routes/api.php`
- Handled by `ImportController@store` in `app/Http/Controllers/ImportController.php`
- Request validation is in `app/Http/Requests/ImportExcelRequest.php`
  - accepts only `xlsx/xls`
  - max size 20 MB
  - optional `dry_run` flag

### CLI endpoint
- Command: `php artisan imports:run {path} [--dry-run] [--imported-by=...]`
- Implemented in `app/Console/Commands/RunImportBatch.php`
- Uses the same service pipeline as API (`ImportBatchService`)

---

## 2) Main Orchestration Flow

Core orchestrator: `app/Services/ImportBatchService.php`

1. Create `import_batches` row (`ImportBatch` model) with initial status:
   - `queued` for normal async import
   - `processing` for dry run
2. Store original file to `storage/app/imports/{batchId}/...`
3. Branch by mode:
   - `dry_run=true`: process immediately in same request
   - normal: dispatch `ImportBatchJob` (`app/Jobs/ImportBatchJob.php`)
4. During processing:
   - set status `processing`
   - run in DB transaction
   - detect format via `ImportFormatDetector`
   - execute matching importer
5. Save counters:
   - `rows_inserted`
   - `rows_skipped`
   - `rows_flagged`
   - `rows_invalid`
6. Final status:
   - `completed` (normal success)
   - `preview_completed` (dry run success)
   - `failed` (+ error message on exception)

---

## 3) Format Detection: Petra vs Legacy

`app/Services/ImportFormatDetector.php`

- Reads workbook and scans first rows of each sheet.
- If a sheet contains all required Petra headers (normalized), format is `petra`:
  - `ConverterRefNo`
  - `AdditionalDescription`
  - `ManufacturerName`
  - `WeightOfCarrier`
  - `PtContentGT`
  - `PdContentGT`
  - `RhContentGT`
- Otherwise, falls back to `legacy`.

Important detail:
- Header normalization removes spaces and lowercases, so minor spacing/case differences are tolerated.

---

## 4) Petra Import Flow

### Classes
- `app/Imports/PetraCatalogImport.php`
- `app/Imports/PetraSheetImport.php`

### Behavior
1. Detect the matching Petra sheet and import only that sheet.
2. Start reading at row 2 (`WithStartRow`), row 1 is expected headers.
3. Column mapping is fixed by index:
   - serial, description, manufacturer, weight, pt, pd, rh
4. Row validity (`isValidRow`):
   - must have `serial_code`
   - must have `model` (manufacturer)
   - at least one of `pt/pd/rh`
5. Duplicate strategy:
   - normalize serial (remove spaces/slashes/dashes/dots + uppercase)
   - find same serial in same car group
   - if exact assay match: skip
   - if same serial but assay differs: create `DuplicateReview` (`pending`)
   - if no match: insert new item (unless dry-run)

Petra importer does **not** create `ImportRowIssue` rows; invalid Petra rows only increase `rows_invalid`.

---

## 5) Legacy Import Flow

Class: `app/Services/LegacyWorkbookImportService.php`

### Sheet handling
- Iterates all sheets in workbook.
- Skips placeholder sheets like `Лист1`, `Лист2`.
- Skips empty sheets.

### Layout/header detection
- Attempts to detect header row in first 3 rows.
- Supports multilingual / noisy headers (including Cyrillic and mojibake-like variants).
- If no clear header found, uses fallback fixed layout (start row 4, hard-coded columns).

### Row mapping
Mapped fields:
- `model`
- `serial_code`
- `weight_kg`
- `pt_ppm`, `pd_ppm`, `rh_ppm`
- `extra_codes`
- `details`
- `shape_code`

### Validation issues (recorded in DB)
When row looks like data but fails validity checks, an `ImportRowIssue` record is created:
- `missing_serial_code`
- `missing_model`
- `missing_assay_values`
- `ambiguous_assay_value`

`ambiguous_assay_value` is raised when assay cells contain non-single numeric patterns like:
- `1250/2300`
- ranges
- text mixed with numbers
- separators such as `/ ; |`

### Duplicate handling (legacy)
Same strategy as Petra:
- same serial + same assay signature => skip
- same serial + different assay => `DuplicateReview` pending
- not found => insert (unless dry-run)

### Extra codes
- Splits `extra_codes` by `/`
- trims + de-duplicates
- persists in `extra_codes` table (`source = excel_import`)

---

## 6) Group/Sheet Name Resolution

Legacy sheets are resolved to `car_groups` via:
- `app/Services/ImportSheetGroupResolver.php`
- aliases in `config/imports.php`

Examples:
- `VW` and `AUDI` -> `AUDI VW`
- `KIA HUNDAY`, `HYUNDAY`, `HUNDAY` -> `KOREA`
- `CHRAISLER` -> `CHRYSLER`
- `SEVEL` -> `FIAT`
- `РАЗНИ` -> `RAZNI`

Also important:
- Prefix `New ` is stripped (`New BMW` -> `BMW`)
- name is uppercased
- missing groups are auto-created in non-dry-run mode

---

## 7) Duplicate Resolution After Import

Endpoints in `ImportController`:
- `GET /api/imports/{batch}`
- `GET /api/imports/{batch}/duplicates`
- `GET /api/imports/{batch}/issues`
- `PATCH /api/duplicates/{review}`

Resolver service: `app/Services/DuplicateResolverService.php`

Actions:
- `keep`: keep existing DB row, mark review as `kept`
- `overwrite`: update existing item with imported payload, replace extra codes
- `insert`: insert new item in same group as existing

All actions run in transaction and set `resolved_by` / `resolved_at`.

---

## 8) Data-Issue Playbook For Your Files

Observed workbook sheet topology (from file metadata):
- `maik 2_101857 (2).xlsx`: many canonical brand sheets (`AUDI VW`, `BMW`, `MERCEDES`, `KOREA`, ...)
- `PETRA UAE CATALOG.xlsx`: `Sheet1`, `Sheet2` (likely Petra-style unified catalog)
- `KIA CATALOG.xlsx`: single `Sheet1`
- `katalog  A&K.xlsx`: many `New ...` sheets + placeholders (`Лист1`, `Лист2`)
- `букши.xls`: legacy `.xls` workbook (binary format)

### A) `katalog  A&K.xlsx` (legacy multi-sheet)
Likely behavior:
- `New ...` sheets are normalized by removing `New ` and alias mapping.
- `Лист1` / `Лист2` are skipped automatically.

Typical issues + fixes:
- If rows become `missing_serial_code`: ensure serial column is populated (not formulas returning blanks).
- If `ambiguous_assay_value`: replace values like `100/200` with a single numeric value per cell.
- If `missing_assay_values`: ensure at least one of PT/PD/RH has numeric value.
- If wrong group assignment: add/adjust alias in `config/imports.php`.

### B) `maik 2_101857 (2).xlsx` (legacy multi-sheet, cleaner naming)
Likely behavior:
- Should map well because sheet names are already canonical.

Typical issues + fixes:
- Same serial with changed assays will be flagged as duplicates (not auto-overwritten).
- Normalize serial consistency in source (avoid mixed punctuation variants to reduce review load).
- Keep assay precision stable to avoid near-duplicate false conflicts.

### C) `PETRA UAE CATALOG.xlsx` (Petra candidate)
Likely behavior:
- If a sheet contains required Petra headers, importer auto-selects it.
- Rows missing serial/manufacturer/assays count as invalid (no issue row details in Petra path).

Typical issues + fixes:
- Ensure exact Petra header columns exist (case/spacing is flexible, naming is not).
- Ensure manufacturer text is present (used for car group resolution).
- Avoid formulas that produce text/non-numeric assays in PT/PD/RH columns.

### D) `KIA CATALOG.xlsx` (could be Petra or legacy)
Likely behavior:
- If Petra headers present -> Petra flow.
- Otherwise -> legacy sheet-based flow.

Typical issues + fixes:
- If single-sheet file but data resembles legacy table, ensure header row is in first 3 rows or align to fallback layout.
- If sheet title is generic (`Sheet1`), model field must be present in rows; otherwise model may be missing and rows invalid.

### E) `букши.xls` (legacy `.xls`)
Likely behavior:
- Goes through legacy parser.

Typical issues + fixes:
- `.xls` often contains encoding and typing inconsistencies; watch for non-numeric assay strings.
- Prefer converting to `.xlsx` after cleaning for safer ingestion and easier pre-validation.

---

## 9) Recommended Import Procedure For These Files

1. Always run dry-run first (`dry_run=true` or `--dry-run`).
2. Check batch summary (`rows_invalid`, `rows_flagged`).
3. Review invalid rows:
   - `GET /api/imports/{batch}/issues`
4. Review duplicates:
   - `GET /api/imports/{batch}/duplicates`
5. Fix source workbook for recurring structural issues (headers, assay formats, serial normalization).
6. Re-run dry-run until `rows_invalid` is acceptable.
7. Run final import (non-dry-run).
8. Resolve remaining duplicates with:
   - `PATCH /api/duplicates/{review}` action: `keep|overwrite|insert`

---

## 10) Important Implementation Notes

- Import is transactional per batch processing run.
- Dry-run does not insert `items`/`extra_codes`/`duplicate_reviews`, but legacy dry-run still records `import_row_issues` for diagnostics.
- Serial matching uses normalized serial both via stored `normalized_serial` and SQL fallback normalization expression.
- Conflict duplicates are intentionally deferred to human resolution, not auto-merged.

---

## 11) Backend code reference

### Routes (Sanctum)

```64:68:routes/api.php
    Route::post('/imports', [ImportController::class, 'store']);
    Route::get('/imports/{batch}', [ImportController::class, 'show']);
    Route::get('/imports/{batch}/duplicates', [ImportController::class, 'duplicates']);
    Route::get('/imports/{batch}/issues', [ImportController::class, 'issues']);
    Route::patch('/duplicates/{review}', [ImportController::class, 'resolveDuplicate']);
```

### HTTP: upload and dispatch

```27:36:app/Http/Controllers/ImportController.php
    public function store(ImportExcelRequest $request): JsonResponse
    {
        $report = $this->importer->import(
            $request->file('file'),
            $request->user()?->email,
            (bool) $request->boolean('dry_run', false),
        );

        return response()->json($report, 201);
    }
```

### Request validation

```14:27:app/Http/Requests/ImportExcelRequest.php
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls',
                'max:20480',
            ],
            'dry_run' => [
                'sometimes',
                'boolean',
            ],
        ];
    }
```

### CLI

```11:41:app/Console/Commands/RunImportBatch.php
    protected $signature = 'imports:run
        {path : Absolute path to an .xls/.xlsx file}
        {--dry-run : Parse and profile only without writing items}
        {--imported-by= : Optional importer identity/email}';

    protected $description = 'Queue or preview an Excel import batch';

    public function handle(ImportBatchService $importBatchService): int
    {
        $path = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');
        $importedBy = $this->option('imported-by');
        $importedBy = is_string($importedBy) && trim($importedBy) !== '' ? trim($importedBy) : null;

        try {
            $report = $importBatchService->importFromPath($path, $importedBy, $dryRun);
        } catch (Throwable $exception) {
            $this->error('Import failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Import batch created successfully.');
        $this->line('Batch ID: '.($report['batch_id'] ?? 'n/a'));
        $this->line('Status: '.($report['status'] ?? 'unknown'));
        $this->line('Rows inserted: '.(int) ($report['rows_inserted'] ?? 0));
        $this->line('Rows skipped: '.(int) ($report['rows_skipped'] ?? 0));
        $this->line('Rows flagged: '.(int) ($report['rows_flagged'] ?? 0));
        $this->line('Rows invalid: '.(int) ($report['rows_invalid'] ?? 0));
        $this->line('Duplicates total: '.(int) ($report['duplicates_total'] ?? 0));
        $this->line('Duplicates pending: '.(int) ($report['duplicates_pending'] ?? 0));

        return self::SUCCESS;
    }
```

### Queue job

```21:29:app/Jobs/ImportBatchJob.php
    public function __construct(
        public readonly string $batchId,
        public readonly string $storedFilePath,
    ) {}

    public function handle(ImportBatchService $importBatchService): void
    {
        $importBatchService->processQueuedBatch($this->batchId, $this->storedFilePath);
    }
```

### Orchestrator: batch, transaction, Petra vs legacy

```37:60:app/Services/ImportBatchService.php
    public function import(UploadedFile $file, ?string $importedBy = null, bool $dryRun = false): array
    {
        $batch = $this->createBatch(
            $file->getClientOriginalName(),
            $importedBy,
            $dryRun ? self::STATUS_PROCESSING : self::STATUS_QUEUED
        );

        $storedPath = $this->storeFile(
            $batch->id,
            (string) $file->getRealPath(),
            $file->getClientOriginalName()
        );

        if ($dryRun) {
            $this->processBatch($batch, $storedPath, true);

            return $this->report($batch->fresh());
        }

        ImportBatchJob::dispatch($batch->id, $storedPath);

        return $this->report($batch);
    }
```

```105:167:app/Services/ImportBatchService.php
    private function processBatch(ImportBatch $batch, string $storedPath, bool $dryRun): void
    {
        $batch->update([
            'status' => self::STATUS_PROCESSING,
            'error_message' => null,
        ]);

        $absolutePath = Storage::disk('local')->path($storedPath);

        if (! is_file($absolutePath)) {
            throw new RuntimeException('Stored import file is missing.');
        }

        try {
            $report = DB::transaction(function () use ($batch, $absolutePath, $dryRun): array {
                return $this->runImport($batch, $absolutePath, $dryRun);
            });

            $batch->update([
                'status' => $dryRun ? self::STATUS_PREVIEW_COMPLETED : self::STATUS_COMPLETED,
                'error_message' => null,
                'rows_inserted' => (int) ($report['rows_inserted'] ?? 0),
                'rows_skipped' => (int) ($report['rows_skipped'] ?? 0),
                'rows_flagged' => (int) ($report['rows_flagged'] ?? 0),
                'rows_invalid' => (int) ($report['rows_invalid'] ?? 0),
            ]);
        } catch (Throwable $e) {
            $batch->update([
                'status' => self::STATUS_FAILED,
                'error_message' => Str::limit($e->getMessage(), 60000),
            ]);

            Log::error('Import failed', [
                'batch_id' => $batch->id,
                'file_name' => $batch->file_name,
                'stored_path' => $storedPath,
                'dry_run' => $dryRun,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    private function runImport(ImportBatch $batch, string $absolutePath, bool $dryRun): array
    {
        $detected = $this->formatDetector->detectFromPath($absolutePath);

        if (($detected['format'] ?? null) === ImportFormatDetector::FORMAT_PETRA) {
            $import = new PetraCatalogImport(
                $batch,
                (string) ($detected['sheet_name'] ?? ''),
                $dryRun,
            );

            Excel::import($import, $absolutePath);

            return $import->report();
        }

        return $this->legacyWorkbookImporter->import($batch, $absolutePath, $dryRun);
    }
```

```214:232:app/Services/ImportBatchService.php
    private function report(ImportBatch $batch): array
    {
        $batch->loadCount([
            'duplicateReviews as duplicates_total',
            'duplicateReviews as duplicates_pending' => fn ($query) => $query->where('status', 'pending'),
            'rowIssues as issues_total',
        ]);

        return [
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'rows_inserted' => $batch->rows_inserted,
            'rows_skipped' => $batch->rows_skipped,
            'rows_flagged' => $batch->rows_flagged,
            'rows_invalid' => $batch->rows_invalid,
            'duplicates_total' => (int) ($batch->duplicates_total ?? 0),
            'duplicates_pending' => (int) ($batch->duplicates_pending ?? 0),
            'issues_total' => (int) ($batch->issues_total ?? 0),
        ];
    }
```

### Format detection (Petra headers)

```18:90:app/Services/ImportFormatDetector.php
    private const PETRA_REQUIRED_HEADERS = [
        'ConverterRefNo',
        'AdditionalDescription',
        'ManufacturerName',
        'WeightOfCarrier',
        'PtContentGT',
        'PdContentGT',
        'RhContentGT',
    ];

    /**
     * @return array{format: string, sheet_name?: string}
     */
    public function detectFromPath(string $filePath): array
    {
        if (! is_file($filePath)) {
            throw new RuntimeException('Cannot inspect uploaded file.');
        }

        $spreadsheet = null;

        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not read Excel file.', previous: $e);
        }

        try {
            $required = array_flip(array_map(
                fn (string $header): string => $this->normalizeHeader($header),
                self::PETRA_REQUIRED_HEADERS
            ));

            foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
                $headers = $this->sheetHeaders($sheet->toArray(null, false, false, false));
                $normalizedHeaders = array_flip(array_map(
                    fn (string $header): string => $this->normalizeHeader($header),
                    $headers
                ));

                if (! empty(array_diff_key($required, $normalizedHeaders))) {
                    continue;
                }

                return [
                    'format' => self::FORMAT_PETRA,
                    'sheet_name' => $sheet->getTitle(),
                ];
            }
        } finally {
            if ($spreadsheet instanceof Spreadsheet) {
                $spreadsheet->disconnectWorksheets();
            }
        }

        return ['format' => self::FORMAT_LEGACY];
    }
```

### Petra: sheet wiring, row pipeline, column map, validity

```20:30:app/Imports/PetraCatalogImport.php
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
```

```40:174:app/Imports/PetraSheetImport.php
    public function startRow(): int
    {
        return 2;
    }

    public function onRow(Row $row): void
    {
        $mapped = $this->mapRow($row->toArray());

        if (! $this->isValidRow($mapped)) {
            $this->invalid++;

            return;
        }

        $group = $this->resolveCarGroup($mapped['model']);
        $normalizedSerial = $this->normalizeSerial((string) $mapped['serial_code']);
        $signature = $this->signature($mapped, $group->id, $normalizedSerial);

        if (isset($this->seenSignatures[$signature])) {
            $this->skipped++;

            return;
        }

        $sameSerialWithinGroup = Item::query()
            ->where('car_group_id', $group->id)
            ->where(function ($query) use ($normalizedSerial): void {
                $query->where('normalized_serial', $normalizedSerial)
                    ->orWhereRaw($this->normalizedSerialSql().' = ?', [$normalizedSerial]);
            })
            ->orderByDesc('created_at')
            ->get();

        if ($sameSerialWithinGroup->isEmpty()) {
            if (! $this->dryRun) {
                $this->insertItem($mapped, $group->id);
            }
            $this->seenSignatures[$signature] = true;
            $this->inserted++;

            return;
        }

        if ($this->hasExactMatchInGroup($sameSerialWithinGroup, $mapped)) {
            $this->seenSignatures[$signature] = true;
            $this->skipped++;

            return;
        }

        if (! $this->dryRun) {
            DuplicateReview::query()->create([
                'batch_id' => $this->batch->id,
                'excel_row' => $row->getIndex(),
                'excel_sheet' => $this->sheetName,
                'payload' => [
                    'model' => $mapped['model'],
                    'serial_code' => $mapped['serial_code'],
                    'normalized_serial' => $normalizedSerial,
                    'weight_kg' => $mapped['weight_kg'],
                    'pt_ppm' => $mapped['pt_ppm'],
                    'pd_ppm' => $mapped['pd_ppm'],
                    'rh_ppm' => $mapped['rh_ppm'],
                    'extra_codes' => null,
                    'details' => $mapped['details'],
                    'shape_code' => null,
                    'match_basis' => 'normalized_serial',
                ],
                'existing_item_id' => $sameSerialWithinGroup->first()->id,
                'status' => 'pending',
            ]);
        }

        $this->seenSignatures[$signature] = true;
        $this->flagged++;
    }

    private function mapRow(array $row): array
    {
        return [
            'serial_code' => $this->cleanString($this->valueAt($row, 0)),
            'details' => $this->cleanString($this->valueAt($row, 1)),
            'model' => $this->cleanString($this->valueAt($row, 2)),
            'weight_kg' => $this->toFloat($this->valueAt($row, 3)),
            'pt_ppm' => $this->toFloat($this->valueAt($row, 4)),
            'pd_ppm' => $this->toFloat($this->valueAt($row, 5)),
            'rh_ppm' => $this->toFloat($this->valueAt($row, 6)),
        ];
    }

    private function isValidRow(array $data): bool
    {
        if (blank($data['serial_code']) || blank($data['model'])) {
            return false;
        }

        return filled($data['pt_ppm'])
            || filled($data['pd_ppm'])
            || filled($data['rh_ppm']);
    }
```

### Legacy: per-sheet loop, invalid issues, duplicate review

```16:24:app/Services/LegacyWorkbookImportService.php
class LegacyWorkbookImportService
{
    private const ISSUE_MISSING_SERIAL = 'missing_serial_code';

    private const ISSUE_MISSING_MODEL = 'missing_model';

    private const ISSUE_MISSING_ASSAY = 'missing_assay_values';

    private const ISSUE_AMBIGUOUS_ASSAY = 'ambiguous_assay_value';
```

```42:156:app/Services/LegacyWorkbookImportService.php
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
```

```158:168:app/Services/LegacyWorkbookImportService.php
    private function shouldSkipSheet(Worksheet $sheet): bool
    {
        $normalizedTitle = Str::lower(trim($sheet->getTitle()));
        $normalizedTitle = preg_replace('/\s+/u', '', $normalizedTitle) ?? $normalizedTitle;

        if (preg_match('/^лист\d*$/u', $normalizedTitle) === 1) {
            return true;
        }

        return ! $this->hasAnyCellData($sheet);
    }
```

```678:771:app/Services/LegacyWorkbookImportService.php
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
```

### Car group from sheet name and aliases

```10:51:app/Services/ImportSheetGroupResolver.php
    public function resolve(string $sheetName, bool $createIfMissing = true): ?CarGroup
    {
        $normalized = $this->normalizeSheetName($sheetName);
        $canonical = $this->canonicalSheetName($normalized);

        $group = CarGroup::query()
            ->whereRaw('UPPER(excel_sheet_name) = ?', [$canonical])
            ->orWhereRaw('UPPER(name) = ?', [$canonical])
            ->first();

        if ($group !== null) {
            return $group;
        }

        if (! $createIfMissing) {
            return null;
        }

        return CarGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => $canonical,
            'excel_sheet_name' => $canonical,
            'region' => null,
        ]);
    }

    public function normalizeSheetName(string $sheetName): string
    {
        $value = preg_replace('/^\s*new\s+/iu', '', trim($sheetName)) ?? trim($sheetName);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return Str::upper($value);
    }

    public function canonicalSheetName(string $sheetName): string
    {
        $aliases = collect((array) config('imports.sheet_aliases', []))
            ->mapWithKeys(fn (string $target, string $source): array => [Str::upper(trim($source)) => Str::upper(trim($target))])
            ->all();

        return $aliases[$sheetName] ?? $sheetName;
    }
```

```3:16:config/imports.php
return [
    'sheet_aliases' => [
        'VW' => 'AUDI VW',
        'AUDI' => 'AUDI VW',
        'KIA HUNDAY' => 'KOREA',
        'KIA HUNDAY.' => 'KOREA',
        'HYUNDAY' => 'KOREA',
        'HUNDAY' => 'KOREA',
        'CHRAISLER' => 'CHRYSLER',
        'ROVER' => 'ROVER&LAND ROVER',
        'SEVEL' => 'FIAT',
        'РАЗНИ' => 'RAZNI',
    ],
];
```

### Duplicate resolution API actions

```13:45:app/Services/DuplicateResolverService.php
    public function resolve(DuplicateReview $review, string $action, string $resolvedBy): void
    {
        $allowed = ['keep', 'overwrite', 'insert'];

        if (! in_array($action, $allowed, true)) {
            throw new InvalidArgumentException(
                "Invalid action '{$action}'. Allowed: " . implode(', ', $allowed)
            );
        }

        if (! $review->isPending()) {
            throw new InvalidArgumentException('Duplicate review is already resolved.');
        }

        DB::transaction(function () use ($review, $action, $resolvedBy) {
            match ($action) {
                'keep' => $this->keep($review),
                'overwrite' => $this->overwrite($review),
                'insert' => $this->insert($review),
            };

            $resolvedStatus = match ($action) {
                'keep' => 'kept',
                'overwrite' => 'overwritten',
                'insert' => 'inserted',
            };

            $review->update([
                'status' => $resolvedStatus,
                'resolved_by' => $resolvedBy,
                'resolved_at' => now(),
            ]);
        });
    }
```

---

## 12) Tests Covering This Logic

Primary coverage:
- `tests/Feature/ImportsApiTest.php`
- `tests/Feature/ImportCommandTest.php`
- `tests/Unit/Services/LegacyWorkbookImportServiceTest.php`

These tests validate:
- Petra auto-detection
- legacy flow and aliases
- dry-run behavior
- duplicate flagging and resolution
- ambiguous assay issue detection
- placeholder sheet skipping

