<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\ItemImageAuditOptions;
use App\Models\Item;
use App\Services\Ecotrade\EcotradeJsonReader;
use App\Services\Media\ItemImageAuditReportWriter;
use App\Services\Media\ItemImageIdentityInspector;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class AuditItemImageLinksCommand extends Command
{
    protected $signature = 'media:audit-item-image-links
        {path=ecotrade_products_all.json : Path to the Ecotrade JSON file}
        {--output= : Output directory; defaults to a timestamped directory under storage/app/reports}
        {--code=* : Restrict the audit to one or more serial or extra codes}
        {--visual : Compare current and reference images using PHP GD}
        {--visual-threshold=0.75 : Scores below this value require visual review}
        {--chunk=250 : Database rows loaded per chunk}
        {--limit= : Maximum number of item images to inspect}';

    protected $description = 'Audit item-to-image identity against Ecotrade JSON without changing media or database records';

    public function handle(
        EcotradeJsonReader $jsonReader,
        ItemImageIdentityInspector $inspector,
    ): int {
        $writer = null;

        try {
            $options = $this->auditOptions();
            $outputDirectory = $this->outputDirectory();
            $this->createOutputDirectory($outputDirectory);
            $inspector->buildReferenceIndex($jsonReader->readIterator((string) $this->argument('path')));
            $writer = new ItemImageAuditReportWriter($outputDirectory);
            $items = $this->itemsQuery($this->normalizedCodes());

            $this->auditItems($items, $writer, $inspector, $options);
            $summary = $writer->finish($inspector->referenceStatistics());
            $writer = null;
            $this->renderSummary($summary);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Item image identity audit failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $writer?->close();
        }
    }

    private function auditOptions(): ItemImageAuditOptions
    {
        $threshold = filter_var($this->option('visual-threshold'), FILTER_VALIDATE_FLOAT);

        if ($threshold === false || $threshold < 0 || $threshold > 1) {
            throw new InvalidArgumentException('--visual-threshold must be between 0 and 1.');
        }

        if ((bool) $this->option('visual') && ! extension_loaded('gd')) {
            throw new RuntimeException('--visual requires the PHP GD extension. Run without --visual for the data audit.');
        }

        return new ItemImageAuditOptions((bool) $this->option('visual'), $threshold);
    }

    private function outputDirectory(): string
    {
        $configured = trim((string) $this->option('output'));

        if ($configured === '') {
            return storage_path('app/reports/item-image-link-audit/'.now()->format('Ymd-His'));
        }

        if ($this->isAbsolutePath($configured)) {
            return rtrim($configured, '\\/');
        }

        return rtrim(base_path($configured), '\\/');
    }

    private function createOutputDirectory(string $outputDirectory): void
    {
        if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0775, true) && ! is_dir($outputDirectory)) {
            throw new RuntimeException('Unable to create audit output directory: '.$outputDirectory);
        }
    }

    /** @param list<string> $normalizedCodes */
    private function itemsQuery(array $normalizedCodes): Builder
    {
        $query = Item::query()
            ->with(['carGroup:id,name,excel_sheet_name', 'extraCodes', 'media'])
            ->whereHas('media', static fn (Builder $media): Builder => $media->where('collection_name', 'images'));

        if ($normalizedCodes === []) {
            return $query;
        }

        return $query->where(function (Builder $codes) use ($normalizedCodes): void {
            $codes->whereIn('normalized_serial', $normalizedCodes)
                ->orWhere(function (Builder $serialCodes) use ($normalizedCodes): void {
                    foreach ($normalizedCodes as $normalizedCode) {
                        $serialCodes->orWhereRaw(
                            "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(serial_code), ' ', ''), '-', ''), '.', ''), '/', '') = ?",
                            [$normalizedCode],
                        );
                    }
                })
                ->orWhereHas('extraCodes', static function (Builder $extraCodes) use ($normalizedCodes): void {
                    $extraCodes->where(static function (Builder $matchingCodes) use ($normalizedCodes): void {
                        foreach ($normalizedCodes as $normalizedCode) {
                            $matchingCodes->orWhereRaw(
                                "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(code), ' ', ''), '-', ''), '.', ''), '/', '') = ?",
                                [$normalizedCode],
                            );
                        }
                    });
                });
        });
    }

    /** @return list<string> */
    private function normalizedCodes(): array
    {
        return collect((array) $this->option('code'))
            ->map(static fn (mixed $code): string => Item::normalizeSerialValue($code))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function auditItems(
        Builder $items,
        ItemImageAuditReportWriter $writer,
        ItemImageIdentityInspector $inspector,
        ItemImageAuditOptions $options,
    ): void {
        $availableItems = (clone $items)->count();
        $limit = $this->limit();
        $selectedItems = $limit === null ? $availableItems : min($availableItems, $limit);
        $processed = 0;
        $progress = $this->output->createProgressBar($selectedItems);
        $progress->start();

        foreach ($items->orderBy('id')->lazy($this->chunkSize()) as $item) {
            if ($limit !== null && $processed >= $limit) {
                break;
            }

            $writer->write($inspector->inspect($item, $options));
            $processed++;
            $progress->advance();
        }

        $progress->finish();
        $this->newLine(2);
    }

    private function chunkSize(): int
    {
        $chunkSize = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);

        if ($chunkSize === false || $chunkSize < 1 || $chunkSize > 1000) {
            throw new InvalidArgumentException('--chunk must be an integer between 1 and 1000.');
        }

        return $chunkSize;
    }

    private function limit(): ?int
    {
        $limit = $this->option('limit');

        if ($limit === null || $limit === '') {
            return null;
        }

        $validated = filter_var($limit, FILTER_VALIDATE_INT);

        if ($validated === false || $validated < 1) {
            throw new InvalidArgumentException('--limit must be a positive integer.');
        }

        return $validated;
    }

    /** @param array<string,mixed> $summary */
    private function renderSummary(array $summary): void
    {
        $this->info('Item image identity audit completed without data or media changes.');
        $this->line('Items scanned: '.$summary['items_scanned']);
        $this->line('Confirmed wrong sources: '.($summary['status_counts']['confirmed_wrong_source'] ?? 0));
        $this->line('Cross-code image hashes: '.$summary['cross_code_image_hashes']);

        foreach ($summary['status_counts'] as $status => $count) {
            $this->line(str_replace('_', ' ', $status).': '.$count);
        }

        $this->line('Reports: '.$summary['output_directory']);
    }

    private function isAbsolutePath(string $path): bool
    {
        return Str::startsWith($path, ['/', '\\']) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
