<?php

namespace App\Console\Commands;

use App\Data\EcotradeProductData;
use App\Models\Item;
use App\Services\Ecotrade\EcotradeCategoryWorkbookImporter;
use App\Services\Ecotrade\EcotradeBrandImporter;
use App\Services\Ecotrade\EcotradeJsonReader;
use App\Services\Ecotrade\EcotradeRecordNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ImportEcotradeCategoriesCommand extends Command
{
    protected $signature = 'ecotrade:import-categories
        {path : Path to Ecotrade JSON file}
        {--dry-run : Validate and show summary without writing to DB}
        {--fresh : Reset existing Ecotrade categories before import}
        {--unlink-missing : Move Ecotrade items not present in the JSON into the Ecotrade Unlinked group}
        {--chunk=1000 : Number of rows processed per chunk}
        {--memory-limit=4G : PHP memory limit to apply before streaming the JSON file}';

    protected $description = 'Import Ecotrade categories into car groups and link matching items to them';

    public function handle(
        EcotradeJsonReader $reader,
        EcotradeRecordNormalizer $normalizer,
        EcotradeBrandImporter $brandImporter,
        EcotradeCategoryWorkbookImporter $workbookImporter,
    ): int {
        DB::beginTransaction();

        try {
            $this->applyMemoryLimit((string) $this->option('memory-limit'));

            $path = $this->resolvePath((string) $this->argument('path'));
            $dryRun = (bool) $this->option('dry-run');
            $fresh = (bool) $this->option('fresh');
            $unlinkMissing = (bool) $this->option('unlink-missing');
            $chunkSize = max(1, (int) $this->option('chunk'));
            $isWorkbook = $this->isWorkbookPath($path);

            $files = [];
            $totals = array_fill_keys($this->reportKeys(), 0);

            $fallbackGroup = null;

            if ($fresh || $unlinkMissing) {
                $fallbackGroup = $this->ensureFallbackGroup();

                if ($fresh) {
                    $totals['groups_reset'] += $this->resetEcotradeCategories($fallbackGroup->id);
                }
            }

            $files[] = $isWorkbook
                ? $workbookImporter->import($path, $dryRun)
                : $this->processFile(
                    $path,
                    $reader,
                    $normalizer,
                    $brandImporter,
                    $dryRun,
                    $chunkSize,
                );

            if ($unlinkMissing && $fallbackGroup instanceof \App\Models\CarGroup) {
                $totals['items_unlinked'] += $this->unlinkMissingItems(
                    $fallbackGroup->id,
                    $files[0]['matched_item_ids'] ?? [],
                    $dryRun,
                );
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            foreach ($this->reportKeys() as $key) {
                $totals[$key] += (int) ($files[0][$key] ?? 0);
            }

            $this->printReport($files, $totals, $dryRun);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->error('Ecotrade category import failed: ' . $exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array<string, int|string>
     */
    private function processFile(
        string $path,
        EcotradeJsonReader $reader,
        EcotradeRecordNormalizer $normalizer,
        EcotradeBrandImporter $brandImporter,
        bool $dryRun,
        int $chunkSize,
    ): array {
        $report = $this->makeEmptyReport($path);
        $chunk = [];

        foreach ($reader->readIterator($path) as $record) {
            $chunk[] = $record;

            if (count($chunk) < $chunkSize) {
                continue;
            }

            $this->processChunk($chunk, $report, $normalizer, $brandImporter, $dryRun);
            $chunk = [];
            gc_collect_cycles();
        }

        if ($chunk !== []) {
            $this->processChunk($chunk, $report, $normalizer, $brandImporter, $dryRun);
        }

        return $report;
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<string, int|string>  $report
     */
    private function processChunk(
        array $records,
        array &$report,
        EcotradeRecordNormalizer $normalizer,
        EcotradeBrandImporter $brandImporter,
        bool $dryRun,
    ): void {
        foreach ($records as $record) {
            $report['rows_scanned']++;

            try {
                $data = $normalizer->normalize($record);
            } catch (Throwable $exception) {
                report($exception);
                $report['rows_invalid']++;

                continue;
            }

            if (! $data->isValid()) {
                $report['rows_invalid']++;

                continue;
            }

            $brand = $brandImporter->import($data);

            if ($brand->wasRecentlyCreated) {
                $report['groups_created']++;
            } else {
                $report['groups_updated']++;
            }

            $matches = $this->matchedItems($data);
            $report['items_scanned'] += $matches->count();

            if ($matches->count() > 1) {
                $report['rows_skipped_ambiguous']++;

                continue;
            }

            if ($matches->isEmpty()) {
                $report['rows_skipped_not_found']++;

                continue;
            }

            $item = $matches->first();

            if (! $item instanceof Item) {
                $report['rows_skipped_not_found']++;

                continue;
            }

            if ($item->car_group_id === $brand->id) {
                $report['rows_skipped_noop']++;

                $report['matched_item_ids'][$item->id] = true;

                continue;
            }

            if (! $dryRun) {
                $item->forceFill([
                    'car_group_id' => $brand->id,
                ])->save();
            }

            $report['items_linked']++;
            $report['matched_item_ids'][$item->id] = true;
        }
    }

    /**
     * @return Collection<int, Item>
     */
    private function matchedItems(EcotradeProductData $data): Collection
    {
        $byUrl = Item::query()
            ->where('source', 'ecotrade')
            ->where('source_url', $data->productUrl)
            ->orderByDesc('updated_at')
            ->get();

        if ($byUrl->isNotEmpty()) {
            return $byUrl;
        }

        return Item::query()
            ->where('source', 'ecotrade')
            ->where('source_hash', $data->sourceHash)
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * @return array<string, int|string>
     */
    private function makeEmptyReport(string $path): array
    {
        return [
            'path' => $path,
            'rows_scanned' => 0,
            'rows_invalid' => 0,
            'groups_created' => 0,
            'groups_updated' => 0,
            'groups_reset' => 0,
            'items_scanned' => 0,
            'items_linked' => 0,
            'items_unlinked' => 0,
            'rows_skipped_noop' => 0,
            'rows_skipped_ambiguous' => 0,
            'rows_skipped_not_found' => 0,
            'matched_item_ids' => [],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function reportKeys(): array
    {
        return [
            'rows_scanned',
            'rows_invalid',
            'groups_created',
            'groups_updated',
            'groups_reset',
            'items_scanned',
            'items_linked',
            'items_unlinked',
            'rows_skipped_noop',
            'rows_skipped_ambiguous',
            'rows_skipped_not_found',
        ];
    }

    /**
     * @param  array<int, array<string, int|string>>  $files
     * @param  array<string, int>  $totals
     */
    private function printReport(array $files, array $totals, bool $dryRun): void
    {
        foreach ($files as $fileReport) {
            $this->line('File: ' . $fileReport['path']);

            foreach ($this->reportKeys() as $key) {
                $this->line($key . ': ' . (int) ($fileReport[$key] ?? 0));
            }
        }

        $this->line('Totals:');

        foreach ($this->reportKeys() as $key) {
            $this->line($key . ': ' . (int) ($totals[$key] ?? 0));
        }

        if ($dryRun) {
            $this->comment('Dry run completed without persisting changes.');
        }
    }

    private function ensureFallbackGroup(): \App\Models\CarGroup
    {
        return \App\Models\CarGroup::query()->firstOrCreate(
            [
                'source' => 'ecotrade',
                'slug' => 'ecotrade-unlinked',
            ],
            [
                'name' => 'Ecotrade Unlinked',
                'excel_sheet_name' => 'ECOTRADE UNLINKED',
                'region' => null,
                'parent_id' => null,
                'source_url' => null,
            ],
        );
    }

    private function resetEcotradeCategories(string $fallbackGroupId): int
    {
        $groups = \App\Models\CarGroup::query()
            ->where('source', 'ecotrade')
            ->where('id', '!=', $fallbackGroupId)
            ->get();

        $count = $groups->count();

        $groups->each(function (\App\Models\CarGroup $carGroup) use ($fallbackGroupId): void {
                $carGroup->items()->update(['car_group_id' => $fallbackGroupId]);
                $carGroup->clearMediaCollection('logo');
                $carGroup->clearMediaCollection('images');
                $carGroup->delete();
            });

        return $count;
    }

    private function unlinkMissingItems(string $fallbackGroupId, array $matchedItemIds, bool $dryRun): int
    {
        $query = Item::query()
            ->where('source', 'ecotrade');

        if ($matchedItemIds !== []) {
            $query->whereNotIn('id', array_keys($matchedItemIds));
        }

        $count = (clone $query)->count();

        if (! $dryRun && $count > 0) {
            $query->update(['car_group_id' => $fallbackGroupId]);
        }

        return $count;
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
            $this->warn('Unable to set memory_limit to ' . $limit . '; current limit remains ' . $current . '.');

            return;
        }

        if ($current !== $limit) {
            $this->line('memory_limit: ' . $current . ' -> ' . $limit);
        }
    }

    private function resolvePath(string $path): string
    {
        if (is_file($path)) {
            return $path;
        }

        $normalizedPath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        $candidates = [
            base_path('excel'.DIRECTORY_SEPARATOR.$normalizedPath),
            storage_path('app'.DIRECTORY_SEPARATOR.'excel'.DIRECTORY_SEPARATOR.$normalizedPath),
            base_path($path),
            storage_path($path),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Ecotrade JSON file not found: ' . $path);
    }

    private function isWorkbookPath(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['xlsx', 'xls', 'ods'], true);
    }
}
