# Catalyst Multi-Analysis and EcoTrade Image Import

## Goal

Import every valid catalyst analysis from all Excel workbooks, including multiple analyses that share the same serial code, while preventing exact assay duplicates and attaching the best available EcoTrade image to every item in the serial family.

## Default Excel folder

Place all `.xls` and `.xlsx` files inside:

```text
excel/
```

The folder can be changed in `.env`:

```dotenv
EXCEL_IMPORT_DIRECTORY=excel
```

The `imports:run` command scans this directory recursively when no path is supplied. It ignores non-Excel files and temporary files whose names start with `~$`.

## Data identity

A catalyst item is uniquely identified by:

- Car group
- Normalized serial code
- Weight
- Pt value
- Pd value
- Rh value

The same serial code may therefore have multiple item rows when its weight or assay differs.

Exact duplicate assays are protected by the `assay_fingerprint` unique index.

## Validation rules

A row is imported only when it has:

- A usable serial code
- Positive weight
- At least one positive Pt, Pd, or Rh value

The import rejects:

- Missing serial codes
- Placeholder serials such as `?`, `??`, `...`, or punctuation-only values
- `KONTROLINIS` control rows
- Missing or non-positive weight
- Rows whose Pt, Pd, and Rh values are all absent or zero
- Ambiguous assay values containing text, ranges, or multiple values

## Safe execution order

### 1. Back up the database and media directory

Back up:

- Database
- `storage/app/public`

### 2. Run migrations

```bash
php artisan migrate
```

The migration:

- Adds `items.assay_fingerprint`
- Backfills existing priceable items
- Removes the old composite assay uniqueness index
- Adds a unique fingerprint index

It does not add a unique index to the serial code. Multiple analyses with the same serial remain allowed.

### 3. Check every Excel file at once

Place every workbook in the `excel` folder, then run:

```bash
php artisan imports:run --dry-run --imported-by=admin@example.com
```

No file path is needed.

The command prints:

- One result row for each Excel file
- File status
- Rows that would be inserted
- Rows that would be skipped
- Flagged rows
- Invalid rows
- Duplicate count
- One combined totals table for all files

Dry-run does not write items, extra codes, media, or issue rows.

A different directory can still be supplied explicitly:

```bash
php artisan imports:run storage/app/other-excel-folder --dry-run
```

### 4. Import every Excel file

After reviewing the dry-run report, run:

```bash
php artisan imports:run --imported-by=admin@example.com
```

No file path is needed. A separate import batch is queued for every `.xls` or `.xlsx` file found in the configured folder.

Run the queue worker:

```bash
php artisan queue:work --tries=1
```

Behavior:

- New serial: inserts the first analysis
- Same serial with a different assay: inserts another item automatically
- Exact same assay: skips the row
- New item with an imaged sibling: copies the sibling media through Spatie Media Library

### 5. Audit one workbook against the EcoTrade JSON

For a detailed Excel-to-EcoTrade source comparison:

```bash
php artisan catalysts:audit-sources \
  "excel/maik 2_101857 (2)(1).xlsx" \
  "ecotrade_products_all.json" \
  --csv-dir=storage/app/catalyst-audit
```

The audit does not write items or media. It reports:

- Excel rows scanned
- Invalid rows
- Exact duplicate assays
- Distinct analyses
- Serial families
- Families containing multiple analyses
- EcoTrade records with valid images
- Rejected placeholder images
- Ambiguous EcoTrade families
- Matched and unmatched serial families
- Potential items with images
- Potential sibling image copies

It also writes CSV files for unmatched and ambiguous families when `--csv-dir` is provided.

### 6. Preview EcoTrade image candidates

```bash
php artisan ecotrade:import-product-images \
  ecotrade_products_all.json \
  --dry-run
```

Matching uses:

- Resolved car group
- Normalized primary serial code
- Normalized extra codes

Known mascot, placeholder, default, and no-image URLs are rejected before any image call.

### 7. Process EcoTrade images

First test one candidate:

```bash
php artisan ecotrade:import-product-images \
  ecotrade_products_all.json \
  --test
```

Then run the full import with an explicit cost limit:

```bash
php artisan ecotrade:import-product-images \
  ecotrade_products_all.json \
  --max-cost-usd=100 \
  --watermark=spatie
```

The image flow:

1. Downloads the EcoTrade source image.
2. Validates that the response is an image.
3. Sends it for cleanup.
4. Rejects the candidate if cleanup does not return an edited image.
5. Applies the Maik Cat watermark.
6. Stores the processed image in the Spatie `images` collection.
7. Copies the processed media to other assay items in the same serial family.

The original supplier image is never attached as a fallback when cleanup fails.

### 8. Backfill sibling media for existing data

Preview:

```bash
php artisan items:sync-sibling-images --dry-run
```

Copy:

```bash
php artisan items:sync-sibling-images
```

This finds the first item with an image in each car-group and serial family, then copies that media to sibling analyses that do not yet have an image.

## API visibility and priceability

An item is priceable when:

- Weight is greater than zero
- At least one of Pt, Pd, or Rh is greater than zero

An item is visible in the public item API when it is priceable and has media in the `images` collection.

Missing metal columns are treated as zero by the price calculation service.

## Idempotency

The workflow can be rerun safely:

- Exact assay fingerprints are skipped or blocked
- Different assays remain insertable
- Existing destination media is not duplicated
- Placeholder image URLs remain excluded
- Failed image candidates can be retried using the existing retry options

## Important deployment note

Restart queue workers after deploying the migration and model changes:

```bash
php artisan queue:restart
```
