<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PurgeMediaReportCommand extends Command
{
    protected $signature = 'media:purge-report
        {report : Path to a generated CSV report that contains media_id rows}
        {--execute : Actually delete media rows and their storage folders. Default is dry-run}';

    protected $description = 'Delete media rows and storage files listed in a generated audit report';

    public function handle(): int
    {
        $reportPath = $this->resolvePath((string) $this->argument('report'));
        if (! is_file($reportPath)) {
            $this->error('Report file not found: '.$reportPath);

            return self::FAILURE;
        }

        $mediaIds = $this->readMediaIdsFromCsv($reportPath);
        if ($mediaIds === []) {
            $this->warn('No media_id rows were found in the report.');

            return self::SUCCESS;
        }

        $records = Media::query()
            ->whereIn('id', $mediaIds)
            ->orderBy('id')
            ->get(['id', 'disk']);

        $this->line('Report rows: '.count($mediaIds));
        $this->line('Existing media rows: '.$records->count());

        if (! $this->option('execute')) {
            $this->warn('Dry run only. Re-run with --execute to delete rows and storage folders.');
            $this->line('Sample media ids: '.implode(', ', $records->take(15)->pluck('id')->all()));

            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ($records as $media) {
            /** @var Media $media */
            $directory = (string) $media->id;
            $disk = $media->disk;
            Storage::disk($disk)->deleteDirectory($directory);
            Media::query()->whereKey($media->id)->delete();
            $deleted++;
        }

        $this->info('Deleted media rows: '.$deleted);

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function readMediaIdsFromCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $header = fgetcsv($handle);
        if (! is_array($header)) {
            fclose($handle);

            return [];
        }

        $mediaIdIndex = array_search('media_id', $header, true);
        if ($mediaIdIndex === false) {
            fclose($handle);

            return [];
        }

        $ids = [];
        while (($row = fgetcsv($handle)) !== false) {
            $value = $row[$mediaIdIndex] ?? null;
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        fclose($handle);

        return array_values(array_unique($ids));
    }

    private function resolvePath(string $value): string
    {
        if (Str::startsWith($value, ['/', '\\']) || (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $value)) {
            return $value;
        }

        return base_path($value);
    }
}
