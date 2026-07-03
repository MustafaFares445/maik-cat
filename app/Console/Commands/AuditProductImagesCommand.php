<?php

namespace App\Console\Commands;

use App\Models\Item;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\Process\Process;

class AuditProductImagesCommand extends Command
{
    protected $signature = 'media:audit-product-images
        {--output=storage/app/media-audit : Output directory for manifests and reports}
        {--python=python : Python executable used to run the scanner}
        {--limit= : Optional limit for the number of media rows to scan}
        {--spam-reference=* : Absolute path to a known non-product image}
        {--wrong-watermark-reference=* : Absolute path to a known wrong-watermark image}';

    protected $description = 'Scan item media images for non-product spam and wrong-watermark candidates';

    public function handle(): int
    {
        $outputDirectory = $this->resolvePath((string) $this->option('output'));
        if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0777, true) && ! is_dir($outputDirectory)) {
            $this->error('Unable to create output directory: '.$outputDirectory);

            return self::FAILURE;
        }

        $manifestPath = $outputDirectory.DIRECTORY_SEPARATOR.'media_manifest.json';
        $summaryPath = $outputDirectory.DIRECTORY_SEPARATOR.'summary.json';
        $spamReportPath = $outputDirectory.DIRECTORY_SEPARATOR.'spam_images.csv';
        $wrongReportPath = $outputDirectory.DIRECTORY_SEPARATOR.'wrong_watermark_images.csv';

        $limit = $this->option('limit');
        $query = Media::query()
            ->where('model_type', Item::class)
            ->where('collection_name', 'images')
            ->where('disk', 'public')
            ->orderBy('id');

        if (is_numeric($limit)) {
            $query->limit((int) $limit);
        }

        $rows = [];
        foreach ($query->get(['id', 'model_id', 'file_name', 'disk', 'mime_type', 'custom_properties']) as $media) {
            /** @var Media $media */
            $rows[] = [
                'media_id' => $media->id,
                'model_id' => $media->model_id,
                'file_name' => $media->file_name,
                'disk' => $media->disk,
                'mime_type' => $media->mime_type,
                'custom_properties' => $media->custom_properties,
                'absolute_path' => $media->getPath(),
            ];
        }

        file_put_contents(
            $manifestPath,
            json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $command = [
            (string) $this->option('python'),
            base_path('scripts/media_audit_scan.py'),
            '--manifest',
            $manifestPath,
            '--output-dir',
            $outputDirectory,
        ];

        if (is_numeric($limit)) {
            $command[] = '--limit';
            $command[] = (string) (int) $limit;
        }

        foreach ((array) $this->option('spam-reference') as $reference) {
            $command[] = '--spam-reference';
            $command[] = $this->resolvePath((string) $reference);
        }

        foreach ((array) $this->option('wrong-watermark-reference') as $reference) {
            $command[] = '--wrong-watermark-reference';
            $command[] = $this->resolvePath((string) $reference);
        }

        $this->line('Scanning '.count($rows).' media row(s)...');

        $process = new Process($command, base_path());
        $process->setTimeout(null);
        $process->run(function (string $type, string $buffer): void {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $this->line($trimmed);
            }
        });

        if (! $process->isSuccessful()) {
            $this->error('Image audit scanner failed.');
            $this->line($process->getErrorOutput());

            return self::FAILURE;
        }

        $spamPurgeCommandPath = $outputDirectory.DIRECTORY_SEPARATOR.'purge_spam_command.txt';
        $wrongPurgeCommandPath = $outputDirectory.DIRECTORY_SEPARATOR.'purge_wrong_watermark_command.txt';
        file_put_contents(
            $spamPurgeCommandPath,
            'php artisan media:purge-report "'.$spamReportPath.'" --execute'.PHP_EOL
        );
        file_put_contents(
            $wrongPurgeCommandPath,
            'php artisan media:purge-report "'.$wrongReportPath.'" --execute'.PHP_EOL
        );

        if (is_file($summaryPath)) {
            $summary = json_decode((string) file_get_contents($summaryPath), true);
            if (is_array($summary)) {
                $this->newLine();
                $this->info('Spam images: '.(int) ($summary['spam_count'] ?? 0));
                $this->info('Wrong watermark images: '.(int) ($summary['wrong_watermark_count'] ?? 0));
                $this->line('Spam report: '.$spamReportPath);
                $this->line('Wrong watermark report: '.$wrongReportPath);
                if (! empty($summary['spam_preview'])) {
                    $this->line('Spam preview: '.$summary['spam_preview']);
                }
                if (! empty($summary['wrong_watermark_preview'])) {
                    $this->line('Wrong watermark preview: '.$summary['wrong_watermark_preview']);
                }
                $this->line('Generated purge command: '.$spamPurgeCommandPath);
            }
        }

        return self::SUCCESS;
    }

    private function resolvePath(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if ($this->isAbsolutePath($value)) {
            return $value;
        }

        return base_path($value);
    }

    private function isAbsolutePath(string $value): bool
    {
        return Str::startsWith($value, ['/', '\\']) || (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $value);
    }
}
