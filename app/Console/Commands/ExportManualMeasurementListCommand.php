<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class ExportManualMeasurementListCommand extends Command
{
    protected $signature = 'pricing:export-manual-measurement-list
        {--input= : Manual measurement candidates JSON path}
        {--output= : CSV output path}';

    protected $description = 'Export the evidence-backed family/variant measurements that still require one physical measurement.';

    public function handle(): int
    {
        $input = (string) ($this->option('input') ?: storage_path('app/reports/manual_measurement_candidates.json'));
        $output = (string) ($this->option('output') ?: storage_path('app/reports/pricing-manual-measurements-'.now()->format('Ymd-His').'.csv'));

        if (! File::exists($input)) {
            throw new RuntimeException('Manual measurement evidence file not found: '.$input);
        }

        $rows = json_decode(File::get($input), true);
        if (! is_array($rows)) {
            throw new RuntimeException('Invalid manual measurement JSON: '.$input);
        }

        File::ensureDirectoryExists(dirname($output));
        $handle = fopen($output, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to create CSV report: '.$output);
        }

        fputcsv($handle, [
            'group',
            'serial',
            'exact_oem_variant_to_measure',
            'what_must_be_weighed',
            'why_external_evidence_was_insufficient',
            'expected_measurements',
        ], ',', '"', '');

        $count = 0;
        $seen = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $group = trim((string) ($row['group'] ?? ''));
            $serial = trim((string) ($row['serial'] ?? ''));
            $key = mb_strtoupper($group.'|'.$serial);

            if ($serial === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            fputcsv($handle, [
                $group,
                $serial,
                (string) ($row['exact_oem_variant_to_measure'] ?? ''),
                (string) ($row['what_must_be_weighed'] ?? ''),
                (string) ($row['why_external_evidence_was_insufficient'] ?? ''),
                (int) ($row['expected_measurements'] ?? 1),
            ], ',', '"', '');
            $count++;
        }

        fclose($handle);

        $this->info("Exported {$count} unresolved family/variant measurements: {$output}");
        $this->line('Source: '.$input);

        return self::SUCCESS;
    }
}
