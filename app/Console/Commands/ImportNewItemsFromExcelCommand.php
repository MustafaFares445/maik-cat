<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Services\ImportSheetGroupResolver;
use App\Support\Items\ItemAssayFingerprint;
use App\Support\Items\LegacyItemWeightNormalizer;
use App\Support\Items\LegacyMaikAssayNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

class ImportNewItemsFromExcelCommand extends Command
{
    protected $signature = 'items:import-new
        {path : Excel workbook path}
        {--dry-run : Parse and simulate without persisting changes}';

    protected $description = 'Import only new item assay variants from a Maik Excel workbook';

    /** @var array<string, true> */
    private array $seenFingerprints = [];

    public function handle(ImportSheetGroupResolver $groupResolver): int
    {
        $path = $this->resolvePath((string) $this->argument('path'));
        $dryRun = (bool) $this->option('dry-run');

        $report = [
            'rows_scanned' => 0,
            'rows_created' => 0,
            'rows_skipped_existing' => 0,
            'rows_skipped_duplicate_in_file' => 0,
            'rows_invalid' => 0,
            'groups_created' => 0,
            'sheets_skipped' => 0,
        ];

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($path);

        DB::beginTransaction();

        try {
            foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
                $this->processSheet($sheet, $groupResolver, $report);
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $exception) {
            DB::rollBack();
            $spreadsheet->disconnectWorksheets();
            $this->error('Import failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $spreadsheet->disconnectWorksheets();

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            collect($report)->map(fn (int $value, string $key): array => [$key, $value])->values()->all()
        );

        if ($dryRun) {
            $this->comment('Dry run completed. No database changes were persisted.');
        } else {
            $this->info('Import completed successfully.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $report
     */
    private function processSheet(
        Worksheet $sheet,
        ImportSheetGroupResolver $groupResolver,
        array &$report,
    ): void {
        $sheetName = trim($sheet->getTitle());

        if (Str::lower($sheetName) === 'kitko') {
            $report['sheets_skipped']++;

            return;
        }

        $canonicalGroupName = $groupResolver->canonicalSheetName(
            $groupResolver->normalizeSheetName($sheetName)
        );

        $group = $groupResolver->resolve($canonicalGroupName, true);

        if ($group === null) {
            throw new RuntimeException('Unable to resolve car group for sheet: '.$sheetName);
        }

        if ($group->wasRecentlyCreated) {
            $group->source = 'excel_import';
            $group->save();
            $report['groups_created']++;
        }

        $layout = [
            'start_row' => 2,
            'weight' => 3,
            'pt' => 4,
            'pd' => 6,
            'rh' => 8,
        ];
        $assayMultiplier = LegacyMaikAssayNormalizer::assayMultiplier($sheet, $layout);

        for ($row = 4; $row <= $sheet->getHighestDataRow(); $row++) {
            $serial = $this->cleanString($sheet->getCellByColumnAndRow(2, $row)->getValue());

            if ($serial === null) {
                continue;
            }

            if (Str::upper($serial) === 'KONTROLINIS') {
                continue;
            }

            $report['rows_scanned']++;

            $model = $this->cleanString($sheet->getCellByColumnAndRow(1, $row)->getValue())
                ?? $group->name;

            $weight = LegacyItemWeightNormalizer::toKilograms(
                $this->readDecimal($sheet, 3, $row, 3)
            );
            $pt = LegacyMaikAssayNormalizer::toPpm(
                $this->readDecimal($sheet, 4, $row, 4),
                $assayMultiplier
            );
            $pd = LegacyMaikAssayNormalizer::toPpm(
                $this->readDecimal($sheet, 6, $row, 4),
                $assayMultiplier
            );
            $rh = LegacyMaikAssayNormalizer::toPpm(
                $this->readDecimal($sheet, 8, $row, 4),
                $assayMultiplier
            );

            if (
                $weight === null
                || $weight <= 0
                || (($pt ?? 0) <= 0 && ($pd ?? 0) <= 0 && ($rh ?? 0) <= 0)
            ) {
                $report['rows_invalid']++;

                continue;
            }

            $normalizedSerial = Item::normalizeSerialValue($serial);
            $fingerprint = ItemAssayFingerprint::make(
                $group->id,
                $normalizedSerial,
                $weight,
                $pt,
                $pd,
                $rh,
            );

            if ($fingerprint === null) {
                $report['rows_invalid']++;

                continue;
            }

            if (isset($this->seenFingerprints[$fingerprint])) {
                $report['rows_skipped_duplicate_in_file']++;

                continue;
            }

            $this->seenFingerprints[$fingerprint] = true;

            if ($this->itemAlreadyExists(
                $group->id,
                $normalizedSerial,
                $weight,
                $pt,
                $pd,
                $rh,
                $fingerprint,
            )) {
                $report['rows_skipped_existing']++;

                continue;
            }

            Item::query()->create([
                'id' => (string) Str::uuid(),
                'car_group_id' => $group->id,
                'model' => $model,
                'serial_code' => $serial,
                'normalized_serial' => $normalizedSerial,
                'weight_kg' => $weight,
                'pt_ppm' => $pt,
                'pd_ppm' => $pd,
                'rh_ppm' => $rh,
                'details' => $this->cleanString(
                    $sheet->getCellByColumnAndRow(13, $row)->getValue()
                ),
                'source' => 'excel_import',
            ]);

            $report['rows_created']++;
        }
    }

    private function itemAlreadyExists(
        string $groupId,
        string $normalizedSerial,
        float $weight,
        ?float $pt,
        ?float $pd,
        ?float $rh,
        string $fingerprint,
    ): bool {
        if (Item::query()->where('assay_fingerprint', $fingerprint)->exists()) {
            return true;
        }

        $query = Item::query()
            ->where('car_group_id', $groupId)
            ->where(function ($query) use ($normalizedSerial): void {
                $query->where('normalized_serial', $normalizedSerial)
                    ->orWhereRaw(
                        "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(serial_code, ''), ' ', ''), '-', ''), '/', ''), '.', '')) = ?",
                        [$normalizedSerial]
                    );
            })
            ->where('weight_kg', $weight);

        foreach (['pt_ppm' => $pt, 'pd_ppm' => $pd, 'rh_ppm' => $rh] as $field => $value) {
            $value === null ? $query->whereNull($field) : $query->where($field, $value);
        }

        return $query->exists();
    }

    private function readDecimal(Worksheet $sheet, int $column, int $row, int $scale): ?float
    {
        $value = $sheet->getCellByColumnAndRow($column, $row)->getValue();

        if ($value === null || $value === '' || (is_string($value) && str_starts_with($value, '='))) {
            return null;
        }

        $cleaned = str_replace([' ', "'", ','], ['', '', '.'], trim((string) $value));

        if (! is_numeric($cleaned)) {
            return null;
        }

        $number = (float) $cleaned;

        if (! is_finite($number) || $number < 0) {
            return null;
        }

        return round($number, $scale, PHP_ROUND_HALF_UP);
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

        return preg_replace('/\s+/u', ' ', $string) ?: null;
    }

    private function resolvePath(string $path): string
    {
        if (is_file($path)) {
            return $path;
        }

        $normalizedPath = ltrim(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path),
            DIRECTORY_SEPARATOR
        );

        $candidates = [
            base_path($path),
            storage_path('app'.DIRECTORY_SEPARATOR.$normalizedPath),
            base_path('excel'.DIRECTORY_SEPARATOR.$normalizedPath),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Excel file not found: '.$path);
    }
}
