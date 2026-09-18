<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class AuditEcotradeOutliersCommand extends Command
{
    protected $signature = 'pricing:audit-ecotrade-outliers
        {--input= : Pricing comparison JSON path}
        {--output= : JSON output path}
        {--threshold=25 : Absolute difference percentage threshold}';

    protected $description = 'Audit local pricing outliers against the generated EcoTrade comparison report without changing pricing data.';

    public function handle(): int
    {
        $threshold = abs((float) $this->option('threshold'));
        $input = (string) ($this->option('input') ?: storage_path('app/reports/client_pricing_behavior_comparison.json'));
        $output = (string) ($this->option('output') ?: storage_path('app/reports/pricing-ecotrade-outliers-'.now()->format('Ymd-His').'.json'));

        if (! File::exists($input)) {
            throw new RuntimeException('Pricing comparison report not found: '.$input);
        }

        $payload = json_decode(File::get($input), true);
        if (! is_array($payload) || ! is_array($payload['rows'] ?? null)) {
            throw new RuntimeException('Invalid pricing comparison JSON: '.$input);
        }

        $rows = [];
        foreach ($payload['rows'] as $row) {
            if (! is_array($row) || ! is_numeric($row['adopted_diff_pct'] ?? null)) {
                continue;
            }

            $difference = (float) $row['adopted_diff_pct'];
            if (abs($difference) <= $threshold) {
                continue;
            }

            $rows[] = [
                'group' => $row['group'] ?? null,
                'serial' => $row['serial'] ?? null,
                'item_id' => $row['item_id'] ?? null,
                'model' => $row['model'] ?? null,
                'classification' => $row['classification'] ?? null,
                'mapping_status' => $row['status'] ?? null,
                'ecotrade_price_eur' => $row['eco_price_eur'] ?? null,
                'ecotrade_price_usd' => $row['eco_price_usd'] ?? null,
                'default_price_usd' => $row['default_price_usd'] ?? null,
                'deductions_price_usd' => $row['deductions_price_usd'] ?? null,
                'weight_only_price_usd' => $row['weight_only_price_usd'] ?? null,
                'adopted_behavior' => $row['adopted_behavior'] ?? null,
                'adopted_price_usd' => $row['adopted_price_usd'] ?? null,
                'difference_percent' => $difference,
                'decision' => $row['decision'] ?? null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => abs((float) $b['difference_percent']) <=> abs((float) $a['difference_percent']));

        File::ensureDirectoryExists(dirname($output));
        File::put($output, json_encode([
            'source' => $input,
            'generated_at' => now()->toIso8601String(),
            'threshold_percent' => $threshold,
            'row_count' => count($rows),
            'rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->info('Audited '.count($rows).' pricing outliers: '.$output);

        return self::SUCCESS;
    }
}
