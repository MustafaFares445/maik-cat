### One-Time Import Plan for `KIA CATALOG.xlsx` with Duplicate Handling

#### Summary
- Use the existing Petra import pipeline (no code changes) to import: `C:\Users\Mustafa_M_Fares\Downloads\Telegram Desktop\KIA CATALOG.xlsx`.
- Apply duplicate handling against existing DB rows using current behavior:
  - Exact match duplicates: skipped automatically.
  - Conflict duplicates (same serial/group, different assay): queued for review.
- Chosen policy: `keep existing`, with **review first** (no auto-resolve).
- Discovery from pre-check:
  - File is Petra-compatible.
  - `Sheet1`, 93 data rows, all valid, manufacturer `H.KIA`.
  - No within-file duplicates/conflicts detected.
  - Predicted against current DB: `rowsInserted=93`, `rowsSkipped=0`, `rowsFlagged=0`, `rowsInvalid=0`.

#### Public API / Interface Changes
- None.
- Reuse existing endpoints and services only:
  - `POST /api/imports`
  - `GET /api/imports/{batch}`
  - `GET /api/imports/{batch}/duplicates`
  - `PATCH /api/duplicates/{review}` (only if needed later)

#### Implementation Steps
- Execute a one-time import through existing backend service flow (equivalent to `POST /api/imports`) using the target file path.
- Capture and persist `batchId` from the import report.
- Fetch batch details and duplicate queue for that batch.
- If `duplicatesPending > 0`, generate a review summary (row, serial, existing item, incoming assay deltas) and stop for review.
- After review, resolve each pending duplicate with action `keep` only when approved.
- Do not add new importer modes, CLI commands, or schema/index changes.

#### Test Plan / Acceptance Criteria
- Import finishes with batch status `completed`.
- Batch metrics are recorded and non-null (`rowsInserted`, `rowsSkipped`, `rowsFlagged`, `rowsInvalid`).
- For this file, expected result is:
  - `rowsInserted=93`
  - `rowsSkipped=0`
  - `rowsFlagged=0`
  - `rowsInvalid=0`
- Duplicate queue check:
  - Expected `0` pending for this specific run.
  - If non-zero, no automatic overwrite/insert is executed.
- Data integrity spot-check:
  - Imported rows map correctly to Petra fields (`ConverterRefNo`, `ManufacturerName`, `WeightOfCarrier`, `Pt/Pd/Rh`).

#### Assumptions
- “If it exists” means duplicate item rows already in the database, not duplicate file uploads.
- One-time operational import is required (not a reusable feature change).
- Conflict policy is `keep existing`, and conflicts are reviewed before resolution.
- Database and app environment are already configured and reachable.
