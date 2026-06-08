<?php

namespace App\Console\Commands;

use App\Data\EcotradeProductImageCandidate;
use App\Services\Ecotrade\EcotradeJsonReader;
use App\Services\Ecotrade\EcotradeProductImageCandidateResolver;
use App\Services\Ecotrade\EcotradeProductImageImporter;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class ImportEcotradeProductImagesCommand extends Command
{
    protected $signature = 'ecotrade:import-product-images
        {path=ecotrade_products_all.json : Path to Ecotrade JSON file}
        {--dry-run : Report candidates and estimated Gemini cost without external image calls or DB writes}
        {--test : Process one image and print the attached media result}
        {--limit= : Maximum number of candidate images to process}
        {--chunk=50 : Number of candidate rows to process per progress chunk}
        {--sleep-ms=0 : Milliseconds to sleep after each processed image}
        {--max-cost-usd= : Required paid-run cost ceiling; test mode defaults to one-image cost}
        {--replace-existing : Replace item images that already exist}
        {--watermark=spatie : Watermark strategy: spatie, ai, or none}
        {--watermark-ai : Ask Gemini to add repeated Maikcat watermark text after cleanup}
        {--watermark-spatie : Add repeated Maikcat watermark text locally before saving to Spatie media}
        {--watermark-text=maikcat : Text used when watermarking is enabled}';

    protected $description = 'Process priceable Ecotrade item images through Gemini and attach the final image to Spatie media';

    public function handle(
        EcotradeJsonReader $reader,
        EcotradeProductImageCandidateResolver $resolver,
        EcotradeProductImageImporter $importer,
    ): int {
        try {
            $path = $this->resolvePath((string) $this->argument('path'));
            $dryRun = (bool) $this->option('dry-run');
            $testMode = (bool) $this->option('test');
            $replaceExisting = (bool) $this->option('replace-existing');
            $watermark = $this->watermarkMode(
                (string) $this->option('watermark'),
                (bool) $this->option('watermark-ai'),
                (bool) $this->option('watermark-spatie'),
            );
            $watermarkText = $this->watermarkText((string) $this->option('watermark-text'));
            $limit = $this->limit($testMode);
            $chunkSize = max(1, (int) $this->option('chunk'));
            $sleepMs = max(0, (int) $this->option('sleep-ms'));

            $resolved = $resolver->resolve($reader->read($path), $replaceExisting, $limit);
            $summary = $resolved['summary'];
            $candidates = $resolved['candidates'];
            $estimatedCost = $this->estimatedCost(count($candidates));

            $this->printPlan($path, $dryRun, $testMode, $replaceExisting, $watermark, $summary, $estimatedCost);

            if ($dryRun) {
                $this->comment('Dry run completed without Gemini calls or media writes.');

                return self::SUCCESS;
            }

            if ($candidates === []) {
                $this->comment('No product images need processing.');

                return self::SUCCESS;
            }

            if (! $this->costAllowed($estimatedCost, $testMode)) {
                return self::FAILURE;
            }

            $processed = 0;
            $imported = 0;
            $failed = 0;
            $testResult = null;
            $bar = $this->output->createProgressBar(count($candidates));
            $bar->start();

            foreach (array_chunk($candidates, $chunkSize) as $chunk) {
                foreach ($chunk as $candidate) {
                    $processed++;

                    try {
                        $media = $importer->import($candidate, $watermark, $watermarkText, $replaceExisting);
                        $imported++;

                        if ($testMode) {
                            $testResult = [
                                'item_id' => $candidate->item->id,
                                'serial_code' => $candidate->item->serial_code,
                                'source_url' => $candidate->sourceImageUrl,
                                'media_id' => $media->id,
                                'media_url' => $media->getUrl(),
                            ];
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                        $failed++;
                        $this->newLine();
                        $this->error('Image failed for item '.$candidate->item->id.': '.$exception->getMessage());
                    }

                    $bar->advance();

                    if ($sleepMs > 0) {
                        usleep($sleepMs * 1000);
                    }
                }
            }

            $bar->finish();
            $this->newLine(2);
            $this->line('Product image import completed.');
            $this->line('- Processed: '.$processed);
            $this->line('- Imported: '.$imported);
            $this->line('- Failed: '.$failed);

            if (is_array($testResult)) {
                $this->newLine();
                $this->line('Test result:');
                $this->line('- Item ID: '.$testResult['item_id']);
                $this->line('- Serial: '.$testResult['serial_code']);
                $this->line('- Source URL: '.$testResult['source_url']);
                $this->line('- Media ID: '.$testResult['media_id']);
                $this->line('- Media URL: '.$testResult['media_url']);
            }

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Ecotrade product image import failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function printPlan(
        string $path,
        bool $dryRun,
        bool $testMode,
        bool $replaceExisting,
        string $watermark,
        array $summary,
        float $estimatedCost,
    ): void {
        $this->newLine();
        $this->line('Ecotrade Product Image Import');
        $this->line('File: '.basename($path));
        $this->line('Dry run: '.($dryRun ? 'yes' : 'no'));
        $this->line('Test mode: '.($testMode ? 'yes' : 'no'));
        $this->line('Replace existing: '.($replaceExisting ? 'yes' : 'no'));
        $this->line('Watermark: '.$watermark);
        $this->newLine();

        foreach ($summary as $key => $value) {
            $this->line(str_replace('_', ' ', $key).': '.$value);
        }

        $this->line('estimated gemini cost usd: $'.number_format($estimatedCost, 4));
    }

    private function costAllowed(float $estimatedCost, bool $testMode): bool
    {
        $option = $this->option('max-cost-usd');
        $maxCost = is_numeric($option) ? (float) $option : null;

        if ($maxCost === null && $testMode) {
            $maxCost = max($estimatedCost * 2, (float) config('services.gemini.image_cost_usd', 0.039387) * 2);
        }

        if ($maxCost === null) {
            $this->error('Paid run requires --max-cost-usd. Use --dry-run first to see the estimate.');

            return false;
        }

        if ($estimatedCost > $maxCost) {
            $this->error('Estimated Gemini cost $'.number_format($estimatedCost, 4).' exceeds --max-cost-usd=$'.number_format($maxCost, 4).'.');

            return false;
        }

        return true;
    }

    private function estimatedCost(int $count): float
    {
        return round($count * (float) config('services.gemini.image_cost_usd', 0.039387), 6);
    }

    private function limit(bool $testMode): ?int
    {
        if ($testMode) {
            return 1;
        }

        $limit = $this->option('limit');

        return is_numeric($limit) ? max(1, (int) $limit) : null;
    }

    private function watermarkMode(string $mode, bool $watermarkAi, bool $watermarkSpatie): string
    {
        if ($watermarkAi && $watermarkSpatie) {
            throw new RuntimeException('Use only one watermark strategy: --watermark-ai or --watermark-spatie.');
        }

        if ($watermarkAi) {
            return 'ai';
        }

        if ($watermarkSpatie) {
            return 'spatie';
        }

        $mode = strtolower(trim($mode));

        if (in_array($mode, ['spatie', 'ai', 'none'], true)) {
            return $mode;
        }

        throw new RuntimeException('Invalid --watermark value. Allowed values: spatie, ai, none.');
    }

    private function watermarkText(string $text): string
    {
        $text = trim($text);

        return $text !== '' ? $text : 'maikcat';
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

        throw new RuntimeException('Ecotrade JSON file not found: '.$path);
    }
}
