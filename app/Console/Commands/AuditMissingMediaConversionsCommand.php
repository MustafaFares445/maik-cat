<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;
use Throwable;

class AuditMissingMediaConversionsCommand extends Command
{
    protected $signature = 'media:audit-missing-conversions
        {--model=App\\Models\\Item : Model class to scan (defaults to Item)}
        {--collection=images : Media collection name to scan}
        {--conversions= : Optional comma-separated conversions to check instead of model-defined conversions}
        {--limit= : Optional limit for the number of media rows to scan}';

    protected $description = 'Regenerate missing Spatie conversion outputs for media rows';

    public function handle(FileManipulator $fileManipulator): int
    {
        try {
            $modelClass = $this->resolveModelClass((string) $this->option('model'));
            $collection = trim((string) $this->option('collection')) ?: 'images';
            $conversionOverride = $this->parseConversions((string) $this->option('conversions'));
            $limit = $this->option('limit');

            $query = Media::query()
                ->where('model_type', $modelClass)
                ->where('collection_name', $collection)
                ->orderBy('id');

            if (is_numeric($limit)) {
                $query->limit((int) $limit);
            }

            $mediaRows = $query->get([
                'id',
                'model_id',
                'model_type',
                'file_name',
                'mime_type',
                'collection_name',
                'disk',
                'conversions_disk',
                'manipulations',
            ]);

            $repairedMedia = 0;
            $repairedConversions = 0;
            $failedMedia = 0;
            $repairPreview = [];

            foreach ($mediaRows as $media) {
                /** @var Media $media */
                $media->manipulations = $media->manipulations ?? [];
                $expectedConversions = $this->expectedConversionsForMedia($media, $conversionOverride);
                $missingConversions = $this->missingConversions($media, $expectedConversions);

                if ($missingConversions === []) {
                    continue;
                }

                $conversionNames = $this->conversionNames($missingConversions);
                if ($conversionNames === []) {
                    continue;
                }

                try {
                    $fileManipulator->performConversions(
                        ConversionCollection::createForMedia($media)
                            ->filter(static fn (Conversion $conversion): bool => in_array($conversion->getName(), $conversionNames, true))
                            ->values(),
                        $media,
                        false
                    );
                } catch (Throwable $exception) {
                    $failedMedia++;
                    $this->error('Failed to regenerate media '.$media->id.': '.$exception->getMessage());

                    continue;
                }

                $repairedMedia++;
                $repairedConversions += count($conversionNames);
                $repairPreview[] = $media->id.': '.implode('|', $conversionNames);
            }

            $this->newLine();
            $this->line('Missing Media Conversions Repair');
            $this->line('Model: '.$modelClass);
            $this->line('Collection: '.$collection);
            $this->line('Media scanned: '.$mediaRows->count());
            $this->line('Media repaired: '.$repairedMedia);
            $this->line('Conversions regenerated: '.$repairedConversions);
            $this->line('Media failed: '.$failedMedia);

            if ($repairPreview !== []) {
                $this->line('Repaired preview: '.implode(', ', array_slice($repairPreview, 0, 5)));
            } else {
                $this->info('No missing conversions were found.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Missing conversions repair failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return list<string>
     */
    private function parseConversions(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $conversion): string => trim($conversion),
            explode(',', $value)
        )));
    }

    /**
     * @return list<Conversion>
     */
    private function expectedConversionsForMedia(Media $media, array $conversionOverride): array
    {
        $conversions = ConversionCollection::createForMedia($media)
            ->values()
            ->all();

        if ($conversionOverride === []) {
            return $conversions;
        }

        return array_values(array_filter(
            $conversions,
            static fn (Conversion $conversion): bool => in_array($conversion->getName(), $conversionOverride, true)
        ));
    }

    /**
     * @param  list<Conversion>  $expectedConversions
     * @return list<string>
     */
    private function missingConversions(Media $media, array $expectedConversions): array
    {
        $missing = [];

        foreach ($expectedConversions as $conversion) {
            if (! $conversion instanceof Conversion) {
                continue;
            }

            if (! $this->conversionFileExists($media, $conversion)) {
                $missing[] = $conversion->getName().' (file missing)';
            }
        }

        return $missing;
    }

    /**
     * @param  list<string>  $missingConversions
     * @return list<string>
     */
    private function conversionNames(array $missingConversions): array
    {
        return array_values(array_map(
            static fn (string $missingConversion): string => trim((string) preg_replace('/\s+\(file missing\)$/', '', $missingConversion)),
            $missingConversions
        ));
    }

    private function conversionFileExists(Media $media, Conversion $conversion): bool
    {
        $diskName = $media->conversions_disk ?: $media->disk;
        $pathGenerator = PathGeneratorFactory::create($media);
        $relativePath = $pathGenerator->getPathForConversions($media).$conversion->getConversionFile($media);

        return Storage::disk($diskName)->exists($relativePath);
    }

    private function resolveModelClass(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new RuntimeException('Model class is required.');
        }

        if (! class_exists($value)) {
            throw new RuntimeException('Model class not found: '.$value);
        }

        if (! is_subclass_of($value, Model::class)) {
            throw new RuntimeException('Model class must extend '.Model::class.': '.$value);
        }

        if (! in_array(HasMedia::class, class_implements($value) ?: [], true)) {
            throw new RuntimeException('Model class must implement '.HasMedia::class.': '.$value);
        }

        return $value;
    }

}
