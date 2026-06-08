<?php

namespace App\Services\Ecotrade;

use App\Models\ImportBatch;
use Illuminate\Console\Command;

class EcotradeImportReporter
{
    private int $total = 0;
    private int $valid = 0;
    private int $invalid = 0;
    private int $failed = 0;
    private int $brandsCreated = 0;
    private int $brandsUpdated = 0;
    private int $brandMediaImported = 0;
    private int $brandMediaSkipped = 0;
    private int $brandMediaFailed = 0;
    private int $productsCreated = 0;
    private int $productsUpdated = 0;
    private int $productsSkipped = 0;
    private int $productImagesSkipped = 0;

    /**
     * @var list<string>
     */
    private array $failureMessages = [];

    public function total(): void
    {
        $this->total++;
    }

    public function valid(): void
    {
        $this->valid++;
    }

    public function invalid(string $reason): void
    {
        $this->invalid++;
        $this->productsSkipped++;
        $this->failureMessages[] = 'Invalid: '.$reason;
    }

    public function failed(string $reason): void
    {
        $this->failed++;
        $this->failureMessages[] = 'Failed: '.$reason;
    }

    public function brandCreated(): void
    {
        $this->brandsCreated++;
    }

    public function brandUpdated(): void
    {
        $this->brandsUpdated++;
    }

    public function brandMediaImported(): void
    {
        $this->brandMediaImported++;
    }

    public function brandMediaSkipped(): void
    {
        $this->brandMediaSkipped++;
    }

    public function brandMediaFailed(): void
    {
        $this->brandMediaFailed++;
    }

    public function productCreated(): void
    {
        $this->productsCreated++;
        $this->productImagesSkipped++;
    }

    public function productUpdated(): void
    {
        $this->productsUpdated++;
        $this->productImagesSkipped++;
    }

    public function productSkipped(): void
    {
        $this->productsSkipped++;
    }

    public function summary(): array
    {
        return [
            'rows_total' => $this->total,
            'rows_valid' => $this->valid,
            'rows_invalid' => $this->invalid,
            'rows_failed' => $this->failed,
            'brands_created' => $this->brandsCreated,
            'brands_updated' => $this->brandsUpdated,
            'brand_media_imported' => $this->brandMediaImported,
            'brand_media_skipped' => $this->brandMediaSkipped,
            'brand_media_failed' => $this->brandMediaFailed,
            'products_created' => $this->productsCreated,
            'products_updated' => $this->productsUpdated,
            'products_skipped' => $this->productsSkipped,
            'product_images_skipped' => $this->productImagesSkipped,
            'failure_messages' => $this->failureMessages,
        ];
    }

    public function print(Command $command, bool $dryRun, bool $fresh, ?ImportBatch $batch = null): void
    {
        $summary = $this->summary();

        $command->newLine();
        $command->line('Ecotrade Import Completed');
        $command->line('File: '.($batch?->file_name ?? 'ecotrade_products_all.json'));
        $command->line('Dry run: '.($dryRun ? 'yes' : 'no'));
        $command->line('Fresh import: '.($fresh ? 'yes' : 'no'));

        if ($batch) {
            $command->line('Batch ID: '.$batch->id);
        }

        $command->newLine();
        $command->line('Rows:');
        $command->line('- Total: '.$summary['rows_total']);
        $command->line('- Valid: '.$summary['rows_valid']);
        $command->line('- Invalid: '.$summary['rows_invalid']);
        $command->line('- Failed: '.$summary['rows_failed']);

        $command->newLine();
        $command->line('Brands:');
        $command->line('- Created: '.$summary['brands_created']);
        $command->line('- Updated: '.$summary['brands_updated']);
        $command->line('- Media imported: '.$summary['brand_media_imported']);
        $command->line('- Media skipped: '.$summary['brand_media_skipped']);
        $command->line('- Media failed: '.$summary['brand_media_failed']);

        $command->newLine();
        $command->line('Products:');
        $command->line('- Created: '.$summary['products_created']);
        $command->line('- Updated: '.$summary['products_updated']);
        $command->line('- Skipped: '.$summary['products_skipped']);

        $command->newLine();
        $command->line('Product images:');
        $command->line('- Skipped by design: '.$summary['product_images_skipped']);

        if ($this->failureMessages !== []) {
            $command->newLine();
            $command->line('Notes:');

            foreach ($this->failureMessages as $message) {
                $command->line('- '.$message);
            }
        }
    }
}
