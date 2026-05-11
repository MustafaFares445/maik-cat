# Excel Import Logic (Detailed)

This document explains how Excel import is implemented in this project, how data flows through the system, and how to handle common data issues for these files:

- `maik 2_101857 (2).xlsx`
- `PETRA UAE CATALOG.xlsx`
- `KIA CATALOG.xlsx`
- `букши.xls`
- `katalog  A&K.xlsx`

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

## 11) Tests Covering This Logic

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

