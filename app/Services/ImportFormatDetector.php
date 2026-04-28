<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use XMLReader;
use ZipArchive;

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
        $zip = new ZipArchive();

        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('Could not open Excel file archive.');
        }

        try {
            $sheets = $this->readWorkbookSheets($zip);
            $sharedStrings = $this->readSharedStrings($zip);
            $required = array_flip(array_map(fn(string $header): string => $this->normalizeHeader($header), self::PETRA_REQUIRED_HEADERS));

            foreach ($sheets as $sheet) {
                $headers = $this->firstRowHeaders($filePath, $sheet['path'], $sharedStrings);
                $normalizedHeaders = array_flip(array_map(fn(string $header): string => $this->normalizeHeader($header), $headers));

                if (empty(array_diff_key($required, $normalizedHeaders))) {
                    return [
                        'format' => self::FORMAT_PETRA,
                        'sheet_name' => $sheet['name'],
                    ];
                }
            }
        } finally {
            $zip->close();
        }

        return ['format' => self::FORMAT_LEGACY];
    }

    /**
     * @return array<int, array{name: string, path: string}>
     */
    private function readWorkbookSheets(ZipArchive $zip): array
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relsXml === false) {
            throw new RuntimeException('Workbook metadata is missing.');
        }

        $ridToTarget = $this->readWorkbookRelations($relsXml);
        $sheets = [];

        $doc = new DOMDocument();
        $doc->loadXML($workbookXml);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        foreach ($xpath->query('/m:workbook/m:sheets/m:sheet') as $sheetNode) {
            $name = (string) $sheetNode->attributes?->getNamedItem('name')?->nodeValue;
            $rid = (string) $sheetNode->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')?->nodeValue;
            $target = $ridToTarget[$rid] ?? null;

            if ($name === '' || $target === null) {
                continue;
            }

            $sheets[] = [
                'name' => $name,
                'path' => 'xl/' . ltrim(str_replace('\\', '/', $target), '/'),
            ];
        }

        return $sheets;
    }

    /**
     * @return array<string, string>
     */
    private function readWorkbookRelations(string $relsXml): array
    {
        $doc = new DOMDocument();
        $doc->loadXML($relsXml);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

        $map = [];
        foreach ($xpath->query('/r:Relationships/r:Relationship') as $node) {
            $id = (string) $node->attributes?->getNamedItem('Id')?->nodeValue;
            $target = (string) $node->attributes?->getNamedItem('Target')?->nodeValue;

            if ($id !== '' && $target !== '') {
                $map[$id] = $target;
            }
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');

        if ($sharedXml === false) {
            return [];
        }

        $doc = new DOMDocument();
        $doc->loadXML($sharedXml);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $strings = [];
        foreach ($xpath->query('//m:si') as $node) {
            $text = '';
            foreach ($xpath->query('.//m:t', $node) as $textNode) {
                $text .= $textNode->textContent;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @return array<int, string>
     */
    private function firstRowHeaders(string $archivePath, string $sheetPath, array $sharedStrings): array
    {
        $uri = sprintf('zip://%s#%s', str_replace('\\', '/', $archivePath), $sheetPath);
        $reader = new XMLReader();

        if (! $reader->open($uri, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            return [];
        }

        $headers = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $rowDepth = $reader->depth;

                while ($reader->read()) {
                    if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'row' && $reader->depth === $rowDepth) {
                        break;
                    }

                    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'c') {
                        continue;
                    }

                    $value = $this->readCellValue($reader, $sharedStrings);
                    if (filled($value)) {
                        $headers[] = trim($value);
                    }
                }

                break;
            }
        } finally {
            $reader->close();
        }

        return $headers;
    }

    /**
     * Reads a single <c/> cell and advances the XMLReader cursor past it.
     *
     * @param  array<int, string>  $sharedStrings
     */
    private function readCellValue(XMLReader $reader, array $sharedStrings): ?string
    {
        $cellXml = $reader->readOuterXml();
        if ($cellXml === '') {
            return null;
        }

        $cell = simplexml_load_string($cellXml);
        if ($cell === false) {
            return null;
        }

        $attributes = $cell->attributes();
        $type = isset($attributes['t']) ? (string) $attributes['t'] : '';

        $valueNode = $cell->xpath('./*[local-name()="v"]');
        if (! empty($valueNode)) {
            $raw = (string) $valueNode[0];

            if ($type === 's') {
                $index = (int) $raw;
                return $sharedStrings[$index] ?? null;
            }

            return $raw;
        }

        $textNodes = $cell->xpath('.//*[local-name()="t"]');
        if (empty($textNodes)) {
            return null;
        }

        $text = '';
        foreach ($textNodes as $node) {
            $text .= (string) $node;
        }

        return $text;
    }

    private function normalizeHeader(string $header): string
    {
        return Str::lower(preg_replace('/\s+/', '', trim($header)) ?? '');
    }
}
