<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Services\ItemSiblingMediaCopier;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SyncSiblingItemImagesCommand extends Command
{
    protected $signature = 'items:sync-sibling-images
        {--dry-run : Count missing sibling images without copying media}';

    protected $description = 'Copy the first image in each catalyst serial family to sibling assay items';

    public function handle(ItemSiblingMediaCopier $copier): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $families = [];
        $sources = 0;
        $copied = 0;

        Item::query()
            ->with('media')
            ->whereHas('media', static function (Builder $query): void {
                $query->where('collection_name', 'images');
            })
            ->orderBy('created_at')
            ->get()
            ->each(function (Item $source) use ($copier, $dryRun, &$families, &$sources, &$copied): void {
                $serial = Item::normalizeSerialValue($source->normalized_serial ?: $source->serial_code);
                $family = $source->car_group_id.'|'.$serial;

                if ($serial === '' || isset($families[$family])) {
                    return;
                }

                $families[$family] = true;
                $sources++;

                if ($dryRun) {
                    $copied += Item::query()
                        ->where('car_group_id', $source->car_group_id)
                        ->where('normalized_serial', $serial)
                        ->whereDoesntHave('media', static function (Builder $query): void {
                            $query->where('collection_name', 'images');
                        })
                        ->count();

                    return;
                }

                $copied += $copier->copyFirstImageToSiblings($source);
            });

        $this->line('Serial families with a source image: '.$sources);
        $this->line(($dryRun ? 'Images that would be copied: ' : 'Images copied: ').$copied);

        if ($dryRun) {
            $this->comment('Dry run completed without media writes.');
        }

        return self::SUCCESS;
    }
}
