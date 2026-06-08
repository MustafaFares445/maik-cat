<?php

namespace App\Services;

use App\Support\Excel\WindowReadFilter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

class ImportFormatDetector
{
    public const FORMAT_LEGACY = 'legacy';

    public const FORMAT_PETRA = 'petra';

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
    public function detect(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new RuntimeException('Cannot inspect uploaded file.');
        }

        return $this->detectFromPath($path);
    }

    /**
     * @return array{format: string, sheet_name?: string}
     */
    public function detectFromPath(string $filePath): array
    {
        if (! is_file($filePath)) {
            throw new RuntimeException('Cannot inspect uploaded file.');
        }

        $sheetInfos = $this->worksheetInfos($filePath);

        try {
            foreach ($sheetInfos as $sheetInfo) {
                $sheetName = $this->worksheetName($sheetInfo);
                if ($sheetName === null) {
                    continue;
                }

                $rowLimit = max(1, min(3, (int) ($sheetInfo['totalRows'] ?? 3)));
                [$spreadsheet, $sheet] = $this->loadWorksheetWindow($filePath, $sheetName, 1, $rowLimit, 25);

                try {
                    $headers = $this->sheetHeaders($sheet->toArray(null, false, false, false));
                    $normalizedHeaders = array_flip(array_map(
                        fn (string $header): string => $this->normalizeHeader($header),
                        $headers
                    ));

                    if (! $this->isPetraHeaders($normalizedHeaders)) {
                        continue;
                    }

                    return [
                        'format' => self::FORMAT_PETRA,
                        'sheet_name' => $sheetName,
                    ];
                } finally {
                    if ($spreadsheet instanceof Spreadsheet) {
                        $spreadsheet->disconnectWorksheets();
                    }
                }
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Could not read Excel file.', previous: $e);
        }

        return ['format' => self::FORMAT_LEGACY];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function worksheetInfos(string $filePath): array
    {
        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);

            return $reader->listWorksheetInfo($filePath);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not inspect workbook structure.', previous: $e);
        }
    }

    /**
     * @param  array<string, mixed>  $sheetInfo
     */
    private function worksheetName(array $sheetInfo): ?string
    {
        $name = (string) ($sheetInfo['worksheetName'] ?? $sheetInfo['sheetName'] ?? '');

        return trim($name) !== '' ? $name : null;
    }

    /**
     * @param  array<string, mixed>  $normalizedHeaders
     */
    private function isPetraHeaders(array $normalizedHeaders): bool
    {
        $required = array_flip(array_map(
            fn (string $header): string => $this->normalizeHeader($header),
            self::PETRA_REQUIRED_HEADERS
        ));

        return empty(array_diff_key($required, $normalizedHeaders));
    }

    /**
     * @return array{0: Spreadsheet, 1: Worksheet}
     */
    private function loadWorksheetWindow(string $filePath, string $sheetName, int $startRow, int $endRow, int $maxColumn): array
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);

        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        $reader->setLoadSheetsOnly([$sheetName]);
        $reader->setReadFilter(new WindowReadFilter($startRow, $endRow, $maxColumn));

        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getSheetByName($sheetName);

        if (! $sheet instanceof Worksheet) {
            $spreadsheet->disconnectWorksheets();

            throw new RuntimeException('Could not load worksheet: '.$sheetName);
        }

        return [$spreadsheet, $sheet];
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<int, string>
     */
    private function sheetHeaders(array $rows): array
    {
        $maxRows = min(3, count($rows));
        $requiredKeys = array_map(fn (string $header): string => $this->normalizeHeader($header), self::PETRA_REQUIRED_HEADERS);
        $bestHeaders = [];
        $bestScore = -1;

        for ($rowIndex = 0; $rowIndex < $maxRows; $rowIndex++) {
            $cells = $rows[$rowIndex] ?? [];
            $headers = array_values(array_filter(array_map(function (mixed $value): ?string {
                if ($value === null) {
                    return null;
                }

                $text = trim((string) $value);

                return $text === '' ? null : $text;
            }, $cells)));

            if ($headers === []) {
                continue;
            }

            $normalized = array_map(fn (string $header): string => $this->normalizeHeader($header), $headers);
            $score = count(array_intersect($requiredKeys, $normalized));

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestHeaders = $headers;
            }
        }

        return $bestHeaders;
    }

    private function normalizeHeader(string $header): string
    {
        return Str::lower(preg_replace('/\s+/', '', trim($header)) ?? '');
    }
}
