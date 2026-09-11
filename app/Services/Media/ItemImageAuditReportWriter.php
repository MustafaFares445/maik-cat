<?php

declare(strict_types=1);

namespace App\Services\Media;

use RuntimeException;

final class ItemImageAuditReportWriter
{
    private const array HEADERS = [
        'status',
        'reason',
        'item_id',
        'car_group_id',
        'car_group',
        'serial_code',
        'normalized_serial',
        'extra_codes',
        'primary_family_key',
        'matched_family_keys',
        'reference_count',
        'item_source_url',
        'item_source_hash',
        'media_id',
        'media_file_name',
        'media_sha256',
        'media_source',
        'media_source_url',
        'media_source_hash',
        'current_image_url',
        'expected_serial_code',
        'expected_product_url',
        'expected_image_url',
        'expected_source_hash',
        'visual_score',
        'visual_error',
    ];

    private const array REVIEW_STATUSES = [
        'confirmed_wrong_source',
        'ambiguous_reference',
        'missing_media_file',
        'missing_reference',
        'missing_provenance',
        'unknown_media_source',
        'likely_visual_mismatch',
        'provenance_match_visual_review',
    ];

    /** @var resource */
    private mixed $allRows;

    /** @var resource */
    private mixed $confirmedRows;

    /** @var resource */
    private mixed $visualReviewRows;

    /** @var resource */
    private mixed $htmlReport;

    /** @var array<string,int> */
    private array $statusCounts = [];

    /** @var array<string,array<string,array<string,array{serial_code:string,expected_source_hash:string,expected_image_url:string}>>> */
    private array $hashUsage = [];

    private bool $closed = false;

    public function __construct(private readonly string $outputDirectory)
    {
        $this->allRows = $this->openCsv('image_link_audit.csv');
        $this->confirmedRows = $this->openCsv('confirmed_wrong_links.csv');
        $this->visualReviewRows = $this->openCsv('visual_review.csv');
        $this->htmlReport = $this->openFile('review.html');
        fwrite($this->htmlReport, $this->htmlHeader());
    }

    /** @param array<string,mixed> $row */
    public function write(array $row): void
    {
        fputcsv($this->allRows, $this->csvValues($row));
        $status = (string) $row['status'];
        $this->statusCounts[$status] = ($this->statusCounts[$status] ?? 0) + 1;

        if ($status === 'confirmed_wrong_source') {
            fputcsv($this->confirmedRows, $this->csvValues($row));
        }

        if (in_array($status, self::REVIEW_STATUSES, true)) {
            fputcsv($this->visualReviewRows, $this->csvValues($row));
            fwrite($this->htmlReport, $this->htmlRow($row));
        }

        $this->rememberHashUsage($row);
    }

    /** @param array<string,int> $referenceStatistics @return array<string,mixed> */
    public function finish(array $referenceStatistics): array
    {
        $crossCodeHashes = $this->writeCrossCodeReport();
        $summary = [
            'completed_at' => now()->toISOString(),
            'output_directory' => $this->outputDirectory,
            'items_scanned' => array_sum($this->statusCounts),
            'status_counts' => $this->statusCounts,
            'cross_code_image_hashes' => $crossCodeHashes,
            'reference_statistics' => $referenceStatistics,
            'reports' => [
                'all' => $this->path('image_link_audit.csv'),
                'confirmed_wrong_links' => $this->path('confirmed_wrong_links.csv'),
                'visual_review' => $this->path('visual_review.csv'),
                'cross_code_image_reuse' => $this->path('cross_code_image_reuse.csv'),
                'html_review' => $this->path('review.html'),
            ],
        ];

        $summaryWritten = file_put_contents(
            $this->path('summary.json'),
            json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        if ($summaryWritten === false) {
            throw new RuntimeException('Unable to write the audit summary.');
        }

        fwrite($this->htmlReport, '</tbody></table></body></html>');
        $this->close();

        return $summary;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        foreach ([$this->allRows, $this->confirmedRows, $this->visualReviewRows, $this->htmlReport] as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $this->closed = true;
    }

    /** @return resource */
    private function openCsv(string $fileName): mixed
    {
        $handle = $this->openFile($fileName);
        fputcsv($handle, self::HEADERS);

        return $handle;
    }

    /** @return resource */
    private function openFile(string $fileName): mixed
    {
        $handle = fopen($this->path($fileName), 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to create audit report: '.$this->path($fileName));
        }

        return $handle;
    }

    /** @param array<string,mixed> $row @return list<mixed> */
    private function csvValues(array $row): array
    {
        return array_map(
            static fn (string $header): mixed => $row[$header] ?? '',
            self::HEADERS,
        );
    }

    /** @param array<string,mixed> $row */
    private function rememberHashUsage(array $row): void
    {
        $hash = (string) $row['media_sha256'];
        $familyKey = (string) $row['primary_family_key'];

        if ($hash === '' || $familyKey === '') {
            return;
        }

        $this->hashUsage[$hash][$familyKey][(string) $row['item_id']] = [
            'serial_code' => (string) $row['serial_code'],
            'expected_source_hash' => (string) $row['expected_source_hash'],
            'expected_image_url' => (string) $row['expected_image_url'],
        ];
    }

    private function writeCrossCodeReport(): int
    {
        $handle = $this->openFile('cross_code_image_reuse.csv');
        fputcsv($handle, ['media_sha256', 'family_count', 'family_key', 'item_id', 'serial_code', 'other_family_keys']);
        $crossCodeHashes = 0;

        foreach ($this->hashUsage as $hash => $families) {
            if (count($families) < 2 || $this->familiesShareOneReference($families)) {
                continue;
            }

            $crossCodeHashes++;
            $familyKeys = array_keys($families);

            foreach ($families as $familyKey => $items) {
                $otherFamilies = implode('|', array_values(array_diff($familyKeys, [$familyKey])));

                foreach ($items as $itemId => $usage) {
                    fputcsv($handle, [$hash, count($families), $familyKey, $itemId, $usage['serial_code'], $otherFamilies]);
                }
            }
        }

        fclose($handle);

        return $crossCodeHashes;
    }

    /** @param array<string,array<string,array{serial_code:string,expected_source_hash:string,expected_image_url:string}>> $families */
    private function familiesShareOneReference(array $families): bool
    {
        $sourceHashes = [];
        $imageUrls = [];

        foreach ($families as $items) {
            foreach ($items as $usage) {
                $sourceHashes[] = $usage['expected_source_hash'];
                $imageUrls[] = $usage['expected_image_url'];
            }
        }

        $sourceHashes = array_values(array_unique($sourceHashes));
        $imageUrls = array_values(array_unique($imageUrls));

        return (count($sourceHashes) === 1 && $sourceHashes[0] !== '')
            || (count($imageUrls) === 1 && $imageUrls[0] !== '');
    }

    /** @param array<string,mixed> $row */
    private function htmlRow(array $row): string
    {
        $text = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $currentImage = $text($row['current_image_url']);
        $expectedImage = $text($row['expected_image_url']);

        return '<tr>'
            .'<td><strong>'.$text($row['status']).'</strong><br>'.$text($row['reason']).'</td>'
            .'<td>'.$text($row['item_id']).'<br>'.$text($row['car_group']).' / '.$text($row['serial_code']).'</td>'
            .'<td>'.($currentImage === '' ? 'Unavailable' : '<img src="'.$currentImage.'" alt="Current image">').'</td>'
            .'<td>'.($expectedImage === '' ? 'Unavailable' : '<img src="'.$expectedImage.'" alt="Ecotrade reference">').'</td>'
            .'<td>'.$text($row['visual_score']).'<br>'.$text($row['media_source_url']).'</td>'
            .'</tr>'.PHP_EOL;
    }

    private function htmlHeader(): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Item image identity audit</title>'
            .'<style>body{font:14px Arial,sans-serif;margin:24px;color:#222}table{border-collapse:collapse;width:100%}'
            .'th,td{border:1px solid #ddd;padding:8px;vertical-align:top}th{background:#f5f5f5;position:sticky;top:0}'
            .'img{display:block;max-width:280px;max-height:220px;object-fit:contain;background:#fafafa}</style>'
            .'</head><body><h1>Item image identity audit</h1><p>Review-only report. No media or database records were changed.</p>'
            .'<table><thead><tr><th>Status</th><th>Item</th><th>Current</th><th>Ecotrade reference</th><th>Score / source</th>'
            .'</tr></thead><tbody>'.PHP_EOL;
    }

    private function path(string $fileName): string
    {
        return $this->outputDirectory.DIRECTORY_SEPARATOR.$fileName;
    }
}
