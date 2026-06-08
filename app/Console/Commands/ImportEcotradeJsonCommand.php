<?php

namespace App\Console\Commands;

use App\Models\CarGroup;
use App\Models\ImportBatch;
use App\Models\Item;
use App\Services\Ecotrade\EcotradeBrandImporter;
use App\Services\Ecotrade\EcotradeBrandMediaImporter;
use App\Services\Ecotrade\EcotradeImportReporter;
use App\Services\Ecotrade\EcotradeJsonReader;
use App\Services\Ecotrade\EcotradeProductImporter;
use App\Services\Ecotrade\EcotradeRecordNormalizer;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class ImportEcotradeJsonCommand extends Command
{
    protected $signature = 'ecotrade:import-json
        {path : Path to Ecotrade JSON file}
        {--brand-images= : Optional JSON file mapping brand slugs to real image URLs}
        {--dry-run : Validate and show summary without writing to DB}
        {--fresh : Delete previous Ecotrade imported products before import}
        {--skip-brand-media : Skip importing brand media}
        {--chunk=1000 : Number of rows processed per chunk}
        {--memory-limit=4G : PHP memory limit to apply before streaming the JSON file}';

    protected $description = 'Import Ecotrade JSON product data into car groups and items';

    public function handle(
        EcotradeJsonReader $reader,
        EcotradeRecordNormalizer $normalizer,
        EcotradeBrandImporter $brandImporter,
        EcotradeProductImporter $productImporter,
        EcotradeBrandMediaImporter $brandMediaImporter,
    ): int {
        $reporter = new EcotradeImportReporter();

        try {
            $this->applyMemoryLimit((string) $this->option('memory-limit'));
            $path = $this->resolvePath((string) $this->argument('path'));
            $chunkSize = max(1, (int) $this->option('chunk'));
            $dryRun = (bool) $this->option('dry-run');
            $fresh = (bool) $this->option('fresh');
            $skipBrandMedia = (bool) $this->option('skip-brand-media');

            $brandImages = $reader->readMapping($this->option('brand-images'));

            $batch = $dryRun ? null : $this->createBatch($path);

            if ($fresh && ! $dryRun) {
                $this->clearEcotradeData();
            }

            $chunk = [];

            foreach ($reader->readIterator($path) as $record) {
                $chunk[] = $record;

                if (count($chunk) < $chunkSize) {
                    continue;
                }

                $this->processRecordsChunk(
                    $chunk,
                    $reporter,
                    $normalizer,
                    $brandImporter,
                    $brandMediaImporter,
                    $productImporter,
                    $brandImages,
                    $batch,
                    $dryRun,
                    $skipBrandMedia,
                );

                $chunk = [];
                gc_collect_cycles();
            }

            if ($chunk !== []) {
                $this->processRecordsChunk(
                    $chunk,
                    $reporter,
                    $normalizer,
                    $brandImporter,
                    $brandMediaImporter,
                    $productImporter,
                    $brandImages,
                    $batch,
                    $dryRun,
                    $skipBrandMedia,
                );
            }

            if ($batch) {
                $summary = $reporter->summary();
                $batch->update([
                    'status' => 'completed',
                    'rows_inserted' => (int) ($summary['products_created'] ?? 0),
                    'rows_skipped' => (int) ($summary['products_skipped'] ?? 0),
                    'rows_flagged' => (int) (($summary['brand_media_failed'] ?? 0) + ($summary['rows_failed'] ?? 0)),
                    'rows_invalid' => (int) ($summary['rows_invalid'] ?? 0),
                    'error_message' => null,
                ]);
            }

            $reporter->print($this, $dryRun, $fresh, $batch);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Ecotrade import failed: ' . $exception->getMessage());

            return self::FAILURE;
        }
    }

    private function createBatch(string $path): ImportBatch
    {
        return ImportBatch::query()->create([
            'file_name' => basename($path),
            'imported_by' => 'system@local',
            'status' => 'processing',
            'error_message' => null,
            'rows_inserted' => 0,
            'rows_skipped' => 0,
            'rows_flagged' => 0,
            'rows_invalid' => 0,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function processRecordsChunk(
        array $records,
        EcotradeImportReporter $reporter,
        EcotradeRecordNormalizer $normalizer,
        EcotradeBrandImporter $brandImporter,
        EcotradeBrandMediaImporter $brandMediaImporter,
        EcotradeProductImporter $productImporter,
        array $brandImages,
        ?ImportBatch $batch,
        bool $dryRun,
        bool $skipBrandMedia,
    ): void {
        foreach ($records as $record) {
            $reporter->total();

            try {
                $data = $normalizer->normalize($record);

                if (! $data->isValid()) {
                    $reporter->invalid((string) $data->invalidReason);
                    continue;
                }

                $reporter->valid();

                if ($dryRun) {
                    continue;
                }

                $brand = $brandImporter->import($data);

                if ($brand->wasRecentlyCreated) {
                    $reporter->brandCreated();
                } else {
                    $reporter->brandUpdated();
                }

                if ($skipBrandMedia) {
                    $reporter->brandMediaSkipped();
                } else {
                    $mediaStatus = $brandMediaImporter->importForBrand(
                        $brand,
                        $data->brandSlug,
                        $brandImages[$data->brandSlug] ?? null,
                    );

                    match ($mediaStatus) {
                        'imported' => $reporter->brandMediaImported(),
                        'failed' => $reporter->brandMediaFailed(),
                        default => $reporter->brandMediaSkipped(),
                    };
                }

                $item = $productImporter->import($data, $brand, $batch);

                if ($item->wasRecentlyCreated) {
                    $reporter->productCreated();
                } else {
                    $reporter->productUpdated();
                }
            } catch (Throwable $exception) {
                report($exception);
                $reporter->failed($exception->getMessage());
            }
        }
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

    private function clearEcotradeData(): void
    {
        Item::query()
            ->where('source', 'ecotrade')
            ->delete();

        CarGroup::query()
            ->where('source', 'ecotrade')
            ->get()
            ->each(function (CarGroup $carGroup): void {
                $carGroup->clearMediaCollection('logo');
                $carGroup->clearMediaCollection('images');
                $carGroup->delete();
            });
    }

    private function resolvePath(string $path): string
    {
        if (is_file($path)) {
            return $path;
        }

        $candidates = [
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
}
