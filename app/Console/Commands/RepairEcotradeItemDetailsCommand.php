<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Services\Ecotrade\EcotradeDetailsTextResolver;
use Illuminate\Console\Command;
use Throwable;

class RepairEcotradeItemDetailsCommand extends Command
{
    protected $signature = 'items:repair-ecotrade-details
        {--dry-run : Preview the changes without saving}
        {--chunk=1000 : Number of items to process per batch}
        {--memory-limit=2G : PHP memory limit to apply before scanning items}';

    protected $description = 'Rewrite Ecotrade item details from stored JSON payloads into plain text';

    public function handle(EcotradeDetailsTextResolver $resolver): int
    {
        $this->applyMemoryLimit((string) $this->option('memory-limit'));

        $chunkSize = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $report = [
            'rows_scanned' => 0,
            'rows_updated' => 0,
            'rows_skipped_noop' => 0,
            'rows_skipped_invalid' => 0,
            'rows_skipped_unresolved' => 0,
        ];

        try {
            Item::query()
                ->where('source', 'ecotrade')
                ->whereNotNull('details')
                ->orderBy('id')
                ->chunkById($chunkSize, function ($items) use (&$report, $dryRun, $resolver): void {
                    foreach ($items as $item) {
                        $report['rows_scanned']++;
                        $rawDetails = (string) $item->details;

                        $payload = json_decode($rawDetails, true);

                        if (! is_array($payload)) {
                            if ($this->looksLikeJsonObject($rawDetails)) {
                                $report['rows_skipped_invalid']++;
                            } else {
                                $report['rows_skipped_noop']++;
                            }

                            continue;
                        }

                        $details = $resolver->resolve($payload);

                        if (! is_string($details) || trim($details) === '') {
                            $report['rows_skipped_unresolved']++;
                            continue;
                        }

                        if ($rawDetails === $details) {
                            $report['rows_skipped_noop']++;
                            continue;
                        }

                        if (! $dryRun) {
                            $item->details = $details;
                            $item->save();
                        }

                        $report['rows_updated']++;
                    }
                });
        } catch (Throwable $exception) {
            $this->error('Item details repair failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        foreach ($report as $key => $value) {
            $this->line($key.': '.(int) $value);
        }

        if ($dryRun) {
            $this->comment('Dry run completed without persisting changes.');
        }

        return self::SUCCESS;
    }

    private function applyMemoryLimit(string $limit): void
    {
        $limit = trim($limit);

        if ($limit === '') {
            return;
        }

        $current = ini_get('memory_limit');
        $previous = @ini_set('memory_limit', $limit);

        if ($previous === false) {
            $this->warn('Unable to set memory_limit to '.$limit.'; current limit remains '.$current.'.');

            return;
        }

        if ($current !== $limit) {
            $this->line('memory_limit: '.$current.' -> '.$limit);
        }
    }

    private function looksLikeJsonObject(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && str_starts_with($value, '{') && str_ends_with($value, '}');
    }
}
