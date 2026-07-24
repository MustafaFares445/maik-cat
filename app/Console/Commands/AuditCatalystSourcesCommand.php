<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Services\Ecotrade\EcotradeJsonReader;
use App\Services\Ecotrade\EcotradeRecordNormalizer;
use App\Services\ImportSheetGroupResolver;
use App\Support\Items\CatalystSerialValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

class AuditCatalystSourcesCommand extends Command
{
    protected $signature = 'catalysts:audit-sources
        {excel : Path to the catalyst Excel workbook}
        {json : Path to the Ecotrade JSON file}
        {--csv-dir= : Optional directory for unmatched and ambiguous CSV reports}';

    protected $description = 'Audit Excel catalyst assays against Ecotrade image records without writing database data';

    public function handle(
        EcotradeJsonReader $jsonReader,
        EcotradeRecordNormalizer $normalizer,
        ImportSheetGroupResolver $groupResolver,
    ): int {
        try {
            $excel = $this->auditExcel(
                $this->resolvePath((string) $this->argument('excel')),
                $groupResolver,
            );
            $json = $this->auditJson(
                $this->resolvePath((string) $this->argument('json')),
                $jsonReader,
                $normalizer,
                $groupResolver,
            );

            $matchedFamilies = array_intersect_key($excel['families'], $json['families']);
            $unmatchedFamilies = array_diff_key($excel['families'], $json['families']);

            $report = [
                'excel_rows_scanned' => $excel['rows_scanned'],
                'excel_rows_invalid' => $excel['rows_invalid'],
                'excel_exact_duplicates' => $excel['exact_duplicates'],
                'excel_distinct_analyses' => $excel['distinct_analyses'],
                'excel_serial_families' => count($excel['families']),
                'excel_multiple_analysis_families' => count(array_filter(
                    $excel['families'],
                    static fn (array $analyses): bool => count($analyses) > 1,
                )),
                'json_records_scanned' => $json['records_scanned'],
                'json_records_invalid' => $json['records_invalid'],
                'json_records_with_valid_image' => $json['records_with_valid_image'],
                'json_rejected_placeholder_images' => $json['rejected_placeholder_images'],
                'json_image_families' => count($json['families']),
                'json_ambiguous_families' => count($json['ambiguous']),
                'matched_serial_families' => count($matchedFamilies),
                'unmatched_excel_families' => count($unmatchedFamilies),
                'potential_items_with_images' => array_sum(array_map('count', $matchedFamilies)),
                'potential_sibling_image_copies' => array_sum(array_map(
                    static fn (array $analyses): int => max(count($analyses) - 1, 0),
                    $matchedFamilies,
                )),
            ];

            foreach ($report as $label => $value) {
                $this->line(str_replace('_', ' ', $label).': '.$value);
            }

            $this->writeCsvReports($unmatchedFamilies, $json['ambiguous']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Catalyst source audit failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array{families:array<string,array<int,string>>,rows_scanned:int,rows_invalid:int,exact_duplicates:int,distinct_analyses:int} */
    private function auditExcel(string $path, ImportSheetGroupResolver $groupResolver): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $families = [];
        $seen = [];
        $rowsScanned = 0;
        $rowsInvalid = 0;
        $exactDuplicates = 0;
        $distinctAnalyses = 0;

        try {
            foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
                if (Str::lower(trim($sheet->getTitle())) === 'kitko') {
                    continue;
                }

                $group = $groupResolver->canonicalSheetName(
                    $groupResolver->normalizeSheetName($sheet->getTitle()),
                );

                for ($row = 4; $row <= $sheet->getHighestDataRow(); $row++) {
                    $serial = $this->clean($sheet->getCellByColumnAndRow(2, $row)->getValue());
                    $weight = $this->number($sheet->getCellByColumnAndRow(3, $row)->getValue());
                    $pt = $this->number($sheet->getCellByColumnAndRow(4, $row)->getValue());
                    $pd = $this->number($sheet->getCellByColumnAndRow(6, $row)->getValue());
                    $rh = $this->number($sheet->getCellByColumnAndRow(8, $row)->getValue());

                    if ($serial === null && $weight === null && $pt === null && $pd === null && $rh === null) {
                        continue;
                    }

                    $rowsScanned++;

                    if (! $this->validAssayRow($serial, $weight, $pt, $pd, $rh)) {
                        $rowsInvalid++;
                        continue;
                    }

                    $family = $group.'|'.Item::normalizeSerialValue($serial);
                    $signature = implode('|', [
                        $family,
                        $this->decimal($weight, 3),
                        $this->decimal($pt, 4),
                        $this->decimal($pd, 4),
                        $this->decimal($rh, 4),
                    ]);

                    if (isset($seen[$signature])) {
                        $exactDuplicates++;
                        continue;
                    }

                    $seen[$signature] = true;
                    $families[$family][] = $signature;
                    $distinctAnalyses++;
                }
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return [
            'families' => $families,
            'rows_scanned' => $rowsScanned,
            'rows_invalid' => $rowsInvalid,
            'exact_duplicates' => $exactDuplicates,
            'distinct_analyses' => $distinctAnalyses,
        ];
    }

    /** @return array{families:array<string,string>,ambiguous:array<string,array<string,true>>,records_scanned:int,records_invalid:int,records_with_valid_image:int,rejected_placeholder_images:int} */
    private function auditJson(
        string $path,
        EcotradeJsonReader $reader,
        EcotradeRecordNormalizer $normalizer,
        ImportSheetGroupResolver $groupResolver,
    ): array {
        $families = [];
        $urlsByFamily = [];
        $recordsScanned = 0;
        $recordsInvalid = 0;
        $recordsWithValidImage = 0;
        $rejectedPlaceholderImages = 0;

        foreach ($reader->readIterator($path) as $record) {
            $recordsScanned++;
            $product = $normalizer->normalize($record);

            if (! $product->isValid() || blank($product->mainImageUrl)) {
                $recordsInvalid++;
                continue;
            }

            if ($this->rejectedImageUrl((string) $product->mainImageUrl)) {
                $rejectedPlaceholderImages++;
                continue;
            }

            $serial = Item::normalizeSerialValue($product->serialCode);

            if ($serial === '' || ! CatalystSerialValidator::isUsable($product->serialCode)) {
                $recordsInvalid++;
                continue;
            }

            $slug = Str::of($product->brandSlug)
                ->trim()
                ->lower()
                ->replace('_', '-')
                ->replace(' ', '-')
                ->toString();
            $groupName = ((array) config('imports.ecotrade_brand_groups', []))[$slug] ?? $product->brandName;
            $group = $groupResolver->canonicalSheetName(
                $groupResolver->normalizeSheetName((string) $groupName),
            );

            if ($group === '') {
                $recordsInvalid++;
                continue;
            }

            $family = $group.'|'.$serial;
            $families[$family] ??= (string) $product->mainImageUrl;
            $urlsByFamily[$family][(string) $product->productUrl] = true;
            $recordsWithValidImage++;
        }

        return [
            'families' => $families,
            'ambiguous' => array_filter(
                $urlsByFamily,
                static fn (array $urls): bool => count($urls) > 1,
            ),
            'records_scanned' => $recordsScanned,
            'records_invalid' => $recordsInvalid,
            'records_with_valid_image' => $recordsWithValidImage,
            'rejected_placeholder_images' => $rejectedPlaceholderImages,
        ];
    }

    private function validAssayRow(?string $serial, ?float $weight, ?float $pt, ?float $pd, ?float $rh): bool
    {
        return CatalystSerialValidator::isUsable($serial)
            && $weight !== null
            && $weight > 0
            && ((float) $pt > 0 || (float) $pd > 0 || (float) $rh > 0);
    }

    private function rejectedImageUrl(string $url): bool
    {
        $url = Str::lower(trim($url));

        foreach ((array) config('imports.rejected_image_url_fragments', []) as $fragment) {
            if ($fragment !== '' && str_contains($url, Str::lower((string) $fragment))) {
                return true;
            }
        }

        return ! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://');
    }

    private function writeCsvReports(array $unmatched, array $ambiguous): void
    {
        $directory = $this->option('csv-dir');

        if (! is_string($directory) || trim($directory) === '') {
            return;
        }

        $directory = rtrim($directory, DIRECTORY_SEPARATOR);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create CSV report directory.');
        }

        $this->writeCsv(
            $directory.'/unmatched_excel_families.csv',
            array_map(static fn (string $family): array => [$family], array_keys($unmatched)),
            ['family'],
        );
        $this->writeCsv(
            $directory.'/ambiguous_ecotrade_families.csv',
            array_map(
                static fn (string $family, array $urls): array => [$family, implode('|', array_keys($urls))],
                array_keys($ambiguous),
                array_values($ambiguous),
            ),
            ['family', 'product_urls'],
        );
    }

    private function writeCsv(string $path, array $rows, array $headers): void
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Could not create CSV report: '.$path);
        }

        try {
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
        } finally {
            fclose($handle);
        }
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || str_starts_with($value, '=') || str_starts_with($value, '#') ? null : $value;
    }

    private function number(mixed $value): ?float
    {
        $value = $this->clean($value);

        if ($value === null) {
            return null;
        }

        $value = str_replace([' ', "'", ','], ['', '', '.'], $value);

        return is_numeric($value) ? (float) $value : null;
    }

    private function decimal(?float $value, int $precision): string
    {
        return $value === null ? 'null' : number_format($value, $precision, '.', '');
    }

    private function resolvePath(string $path): string
    {
        if (is_file($path)) {
            return $path;
        }

        foreach ([base_path($path), storage_path($path), storage_path('app/'.$path)] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Source file not found: '.$path);
    }
}
