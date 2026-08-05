<?php

namespace App\Support\Excel;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use RuntimeException;
use ZipArchive;

final class WindowedWorkbook
{
    /** @var array<string,string> */
    private static array $preparedPaths = [];

    /** @var array<string,true> */
    private static array $temporaryPaths = [];

    private static bool $cleanupRegistered = false;

    public static function reader(string $sourcePath): IReader
    {
        if (strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) === 'xlsx' && ! self::isZipFile($sourcePath)) {
            return IOFactory::createReader('Xls');
        }

        return IOFactory::createReaderForFile($sourcePath);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function worksheetInfos(string $sourcePath): array
    {
        if (strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) !== 'xlsx' || ! self::isZipFile($sourcePath)) {
            $reader = self::reader($sourcePath);
            $reader->setReadDataOnly(true);

            return $reader->listWorksheetInfo($sourcePath);
        }

        $realPath = realpath($sourcePath);
        if ($realPath === false) {
            throw new RuntimeException('Excel source file does not exist.');
        }

        $path = $realPath;
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open XLSX source file.');
        }

        try {
            $infos = [];

            foreach (self::worksheetEntries($zip) as $sheetName => $index) {
                $worksheetName = $zip->getNameIndex($index);
                $worksheetXml = is_string($worksheetName) ? self::readEntryPrefix($zip, $worksheetName) : null;
                $dimension = self::worksheetDimension($worksheetXml);

                $infos[] = [
                    'worksheetName' => $sheetName,
                    'lastColumnIndex' => $dimension['last_column_index'],
                    'lastColumnLetter' => $dimension['last_column_letter'],
                    'totalRows' => $dimension['total_rows'],
                    'totalColumns' => $dimension['total_columns'],
                ];
            }

            return $infos;
        } finally {
            $zip->close();
        }
    }

    /** @param list<string> $sheetNames */
    public static function path(string $sourcePath, int $maxColumn = 25, array $sheetNames = []): string
    {
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        if ($extension !== 'xlsx' || ! self::isZipFile($sourcePath)) {
            return $sourcePath;
        }

        $realPath = realpath($sourcePath);
        if ($realPath === false) {
            throw new RuntimeException('Excel source file does not exist.');
        }

        sort($sheetNames, SORT_STRING);
        $cacheKey = $realPath.'|'.$maxColumn.'|'.implode(',', $sheetNames);
        if (isset(self::$preparedPaths[$cacheKey])) {
            return self::$preparedPaths[$cacheKey];
        }

        $zip = new ZipArchive;
        if ($zip->open($realPath) !== true) {
            throw new RuntimeException('Could not open XLSX source file.');
        }

        try {
            $worksheetEntries = self::worksheetEntries($zip);
            $worksheetIndexes = array_values($worksheetEntries);
            $selectedSheetNames = $sheetNames;
            $normalizationIndexes = $selectedSheetNames === []
                ? $worksheetIndexes
                : array_values(array_intersect_key($worksheetEntries, array_flip($selectedSheetNames)));
            $requiresNormalization = false;

            foreach ($normalizationIndexes as $index) {
                $entryName = $zip->getNameIndex($index);
                $xml = is_string($entryName) ? self::readEntryPrefix($zip, $entryName) : null;

                if (is_string($xml) && self::hasOversizedColumnSpan($xml)) {
                    $requiresNormalization = true;
                    break;
                }
            }

            if (! $requiresNormalization) {
                return self::$preparedPaths[$cacheKey] = $realPath;
            }

            $temporaryPath = tempnam(sys_get_temp_dir(), 'maik_windowed_');
            if ($temporaryPath === false) {
                throw new RuntimeException('Could not create temporary XLSX source file.');
            }

            $output = new ZipArchive;
            if ($output->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                @unlink($temporaryPath);

                throw new RuntimeException('Could not create normalized XLSX source file.');
            }

            try {
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $entryName = $zip->getNameIndex($index);
                    if (! is_string($entryName)) {
                        continue;
                    }

                    $contents = $zip->getFromIndex($index);
                    if (! is_string($contents)) {
                        continue;
                    }

                    if (in_array($index, $normalizationIndexes, true)) {
                        $contents = self::removeCellsOutsideWindow($contents, $maxColumn);
                    }

                    $output->addFromString($entryName, $contents);
                }
            } finally {
                $output->close();
            }

            self::$temporaryPaths[$temporaryPath] = true;
            self::registerCleanup();

            return self::$preparedPaths[$cacheKey] = $temporaryPath;
        } finally {
            $zip->close();
        }
    }

    /** @return array<string,int> */
    private static function worksheetEntries(ZipArchive $zip): array
    {
        $workbook = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $relationships = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));

        if ($workbook === false || $relationships === false) {
            throw new RuntimeException('Could not inspect XLSX workbook structure.');
        }

        $workbookNamespaces = $workbook->getNamespaces(true);
        $main = $workbook->children($workbookNamespaces[''] ?? null);
        $relationshipNamespace = $workbookNamespaces['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $relationshipTargets = [];

        foreach ($relationships->Relationship as $relationship) {
            $attributes = $relationship->attributes();
            $relationshipTargets[(string) ($attributes['Id'] ?? '')] = (string) ($attributes['Target'] ?? '');
        }

        $entries = [];

        foreach ($main->sheets->sheet as $sheet) {
            $attributes = $sheet->attributes();
            $relationshipAttributes = $sheet->attributes($relationshipNamespace);
            $relationshipId = (string) ($relationshipAttributes['id'] ?? '');
            $target = $relationshipTargets[$relationshipId] ?? '';
            $index = $zip->locateName('xl/'.ltrim($target, '/'));

            if ($index !== false) {
                $entries[(string) ($attributes['name'] ?? '')] = $index;
            }
        }

        return $entries;
    }

    private static function hasOversizedColumnSpan(string $xml): bool
    {
        return str_contains($xml, 'spans="1:16384"')
            || preg_match('/<dimension\b[^>]*\bref="[^"]*XFD/i', $xml) === 1;
    }

    private static function readEntryPrefix(ZipArchive $zip, string $entryName): ?string
    {
        $stream = $zip->getStream($entryName);

        if (! is_resource($stream)) {
            return null;
        }

        try {
            $prefix = fread($stream, 8192);

            return is_string($prefix) ? $prefix : null;
        } finally {
            fclose($stream);
        }
    }

    /** @return array{last_column_index:int,last_column_letter:string,total_rows:int,total_columns:int} */
    private static function worksheetDimension(string $xml): array
    {
        $head = substr($xml, 0, 5000);
        $reference = '';

        if (preg_match('/<dimension\b[^>]*\bref="([^"]+)"/i', $head, $matches) === 1) {
            $reference = $matches[1];
        }

        $endReference = str_contains($reference, ':') ? (string) strrchr($reference, ':') : $reference;
        $endReference = ltrim($endReference, ':');

        if ($endReference === '') {
            return [
                'last_column_index' => 1,
                'last_column_letter' => 'A',
                'total_rows' => 1,
                'total_columns' => 1,
            ];
        }

        [$column, $row] = Coordinate::coordinateFromString($endReference);
        $columnIndex = Coordinate::columnIndexFromString($column);

        return [
            'last_column_index' => $columnIndex,
            'last_column_letter' => $column,
            'total_rows' => max(1, (int) $row),
            'total_columns' => max(1, $columnIndex),
        ];
    }

    private static function removeCellsOutsideWindow(string $xml, int $maxColumn): string
    {
        $pattern = '~<c\b(?=[^>]*\br="([A-Z]+)\d+")[^>]*(?:/>|>.*?</c>)~s';

        return preg_replace_callback($pattern, static function (array $matches) use ($maxColumn): string {
            return Coordinate::columnIndexFromString($matches[1]) <= $maxColumn ? $matches[0] : '';
        }, $xml) ?? $xml;
    }

    private static function registerCleanup(): void
    {
        if (self::$cleanupRegistered) {
            return;
        }

        self::$cleanupRegistered = true;
        register_shutdown_function(static function (): void {
            foreach (array_keys(self::$temporaryPaths) as $path) {
                @unlink($path);
            }
        });
    }

    private static function isZipFile(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            return fread($handle, 2) === 'PK';
        } finally {
            fclose($handle);
        }
    }
}
