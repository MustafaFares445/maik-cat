<?php

namespace App\Services\Ecotrade;

use App\Data\EcotradeProductData;
use App\Models\CarGroup;
use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class EcotradeSourceRepairService
{
    public function __construct(
        private readonly EcotradeJsonReader $reader,
        private readonly EcotradeRecordNormalizer $normalizer,
        private readonly EcotradeBrandImporter $brandImporter,
        private readonly EcotradeDetailsTextResolver $detailsTextResolver,
    ) {}

    /**
     * @param  array<int, string>  $paths
     * @return array{
     *   files: array<int, array<string, int|string>>,
     *   totals: array<string, int>
     * }
     */
    public function repairFiles(array $paths, bool $dryRun = false): array
    {
        if ($dryRun) {
            return $this->runDry(function () use ($paths): array {
                return $this->processFiles($paths);
            });
        }

        return $this->processFiles($paths);
    }

    /**
     * @param  array<int, string>  $paths
     * @return array{
     *   files: array<int, array<string, int|string>>,
     *   totals: array<string, int>
     * }
     */
    private function processFiles(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            $files[] = $this->processFile($path);
            gc_collect_cycles();
        }

        return [
            'files' => $files,
            'totals' => $this->sumReports($files),
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function processFile(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Import source file does not exist: '.$path);
        }

        $report = $this->makeEmptyReport($path);
        $records = $this->reader->read($path);

        foreach ($records as $record) {
            $report['rows_scanned']++;

            try {
                $data = $this->normalizer->normalize($record);
            } catch (Throwable $exception) {
                report($exception);
                $report['rows_invalid']++;

                continue;
            }

            if (! $data->isValid()) {
                $report['rows_invalid']++;

                continue;
            }

            $brand = $this->brandImporter->import($data);

            if ($brand->wasRecentlyCreated) {
                $report['groups_created']++;
            }

            $matches = $this->matchedItems($data);

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

            $updates = $this->sourceUpdates($item, $data, $brand);

            if ($updates === []) {
                $report['rows_skipped_noop']++;

                continue;
            }

            $item->fill($updates);
            $item->save();

            $report['rows_updated']++;
        }

        return $report;
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
     * @return array<string, mixed>
     */
    private function sourceUpdates(Item $item, EcotradeProductData $data, CarGroup $brand): array
    {
        $updates = [
            'car_group_id' => $brand->id,
            'model' => $data->productName,
            'serial_code' => $data->serialCode,
            'normalized_serial' => Item::normalizeSerialValue($data->serialCode),
            'details' => $this->detailsTextResolver->resolve($data->raw),
            'source' => 'ecotrade',
            'source_url' => $data->productUrl,
            'source_hash' => $data->sourceHash,
        ];

        return array_filter($updates, function (mixed $value, string $field) use ($item): bool {
            return $item->{$field} !== $value;
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  array<int, array<string, int|string>>  $files
     * @return array<string, int>
     */
    private function sumReports(array $files): array
    {
        $totals = $this->numericReportKeys();
        $summary = array_fill_keys($totals, 0);

        foreach ($files as $report) {
            foreach ($totals as $key) {
                $summary[$key] += (int) ($report[$key] ?? 0);
            }
        }

        return $summary;
    }

    /**
     * @return array<string, int|string>
     */
    private function makeEmptyReport(string $path): array
    {
        return [
            'path' => $path,
            'rows_scanned' => 0,
            'rows_updated' => 0,
            'rows_created' => 0,
            'groups_created' => 0,
            'rows_invalid' => 0,
            'rows_skipped_noop' => 0,
            'rows_skipped_ambiguous' => 0,
            'rows_skipped_not_found' => 0,
            'rows_skipped_duplicate_in_file' => 0,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function numericReportKeys(): array
    {
        return [
            'rows_scanned',
            'rows_updated',
            'rows_created',
            'groups_created',
            'rows_invalid',
            'rows_skipped_noop',
            'rows_skipped_ambiguous',
            'rows_skipped_not_found',
            'rows_skipped_duplicate_in_file',
        ];
    }

    /**
     * @param  callable(): array{files: array<int, array<string, int|string>>, totals: array<string, int>}  $callback
     * @return array{files: array<int, array<string, int|string>>, totals: array<string, int>}
     */
    private function runDry(callable $callback): array
    {
        DB::beginTransaction();

        try {
            $result = $callback();
            DB::rollBack();

            return $result;
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }
}
