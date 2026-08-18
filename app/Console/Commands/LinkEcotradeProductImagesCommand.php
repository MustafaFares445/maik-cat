<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ecotrade\EcotradeDirectProductImageImporter;
use App\Services\Ecotrade\EcotradeJsonReader;
use App\Services\Ecotrade\EcotradeProductImageCandidateResolver;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

class LinkEcotradeProductImagesCommand extends Command
{
    protected $signature = 'ecotrade:link-product-images
        {path=ecotrade_products_all.json : Path to Ecotrade JSON file}
        {--dry-run : Report candidates without downloading images or writing media}
        {--limit= : Maximum number of Ecotrade product candidates to process}
        {--item-ids-file= : JSON file containing the item IDs allowed for linking}
        {--allow-cross-group : Permit unique cross-group matches for allowlisted items}
        {--sleep-ms=0 : Milliseconds to sleep after each processed candidate}
        {--replace-existing : Replace item images that already exist}';

    protected $description = 'Download Ecotrade source images and link them directly to priceable items without Gemini processing';

    public function handle(
        EcotradeJsonReader $reader,
        EcotradeProductImageCandidateResolver $resolver,
        EcotradeDirectProductImageImporter $importer,
    ): int {
        try {
            $path = (string) $this->argument('path');
            $dryRun = (bool) $this->option('dry-run');
            $replaceExisting = (bool) $this->option('replace-existing');
            $limit = $this->limit();
            $sleepMs = max(0, (int) $this->option('sleep-ms'));
            $allowedItemIds = $this->allowedItemIds();
            $allowCrossGroup = (bool) $this->option('allow-cross-group');

            if ($allowCrossGroup && $allowedItemIds === null) {
                throw new RuntimeException('--allow-cross-group requires --item-ids-file.');
            }

            $options = [
                'replace_existing' => $replaceExisting,
                'limit' => $limit,
                'allow_cross_group' => $allowCrossGroup,
            ];

            if ($allowedItemIds !== null) {
                $options['allowed_item_ids'] = $allowedItemIds;
            }

            $resolved = $resolver->resolve($reader->read($path), $options);
            $summary = $resolved['summary'];
            $candidates = $resolved['candidates'];

            $this->line('Ecotrade direct image linking');
            $this->line('File: '.basename($path));
            $this->line('Dry run: '.($dryRun ? 'yes' : 'no'));
            $this->line('Replace existing: '.($replaceExisting ? 'yes' : 'no'));
            $this->line('Allowed item IDs: '.($allowedItemIds === null ? 'all' : count($allowedItemIds)));
            $this->line('Allow cross-group matches: '.($allowCrossGroup ? 'yes' : 'no'));

            foreach ($summary as $key => $value) {
                $this->line(str_replace('_', ' ', $key).': '.$value);
            }

            if ($dryRun) {
                $this->comment('Dry run completed without downloads or media writes.');

                return self::SUCCESS;
            }

            if ($candidates === []) {
                $this->comment('No Ecotrade product images need linking.');

                return self::SUCCESS;
            }

            $processed = 0;
            $linked = 0;
            $failed = 0;
            $bar = $this->output->createProgressBar(count($candidates));
            $bar->start();

            foreach ($candidates as $candidate) {
                $processed++;

                try {
                    $linked += $importer->import($candidate, $replaceExisting);
                } catch (Throwable $exception) {
                    $failed++;
                    $this->error('Image failed for item '.$candidate->item->id.': '.$exception->getMessage());
                }

                $bar->advance();

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }

            $bar->finish();
            $this->newLine(2);
            $this->line('Direct Ecotrade image linking completed.');
            $this->line('- Processed candidates: '.$processed);
            $this->line('- Database items linked: '.$linked);
            $this->line('- Failed: '.$failed);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Ecotrade direct image linking failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function limit(): ?int
    {
        $limit = $this->option('limit');

        return is_numeric($limit) ? max(1, (int) $limit) : null;
    }

    /** @return list<string>|null */
    private function allowedItemIds(): ?array
    {
        $path = trim((string) $this->option('item-ids-file'));

        if ($path === '') {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Unable to read allowed item IDs file: '.$path);
        }

        try {
            $itemIds = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Allowed item IDs file must contain valid JSON.', previous: $exception);
        }

        if (! is_array($itemIds) || ! array_is_list($itemIds)) {
            throw new RuntimeException('Allowed item IDs file must contain a JSON array.');
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $itemId): string => trim((string) $itemId),
            $itemIds,
        ))));
    }
}
