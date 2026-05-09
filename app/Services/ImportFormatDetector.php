<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
