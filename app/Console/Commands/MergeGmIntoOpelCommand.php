<?php

namespace App\Console\Commands;

use App\Models\CarGroup;
use App\Models\Item;
use App\Support\Items\ItemAssayFingerprint;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MergeGmIntoOpelCommand extends Command
{
    protected $signature = 'car-groups:merge-gm-into-opel
        {--apply : Apply the merge. Without this option the command is a dry run.}';

    protected $description = 'Move all GM items and child groups into OPEL, then remove GM categories';

    public function handle(): int
    {
        $gmGroups = $this->gmGroups();

        if ($gmGroups->isEmpty()) {
            $this->info('No GM category exists. Nothing to merge.');

            return self::SUCCESS;
        }

        $opel = $this->opelGroup();

        if (! $opel instanceof CarGroup) {
            $this->error('OPEL category was not found. Create or restore OPEL before running this command.');

            return self::FAILURE;
        }

        $gmIds = $gmGroups->modelKeys();
        $items = Item::query()
            ->whereIn('car_group_id', $gmIds)
            ->orderBy('id')
            ->get();

        $hasFingerprint = Schema::hasColumn('items', 'assay_fingerprint');
        $collisionCount = $hasFingerprint
            ? $this->countTargetFingerprintCollisions($items, $opel, $gmIds)
            : 0;

        $childCount = CarGroup::query()
            ->whereIn('parent_id', $gmIds)
            ->whereKeyNot($opel->getKey())
            ->count();

        $this->table(
            ['Metric', 'Value'],
            [
                ['GM categories', $gmGroups->count()],
                ['Target OPEL', (string) $opel->getKey()],
                ['GM items', $items->count()],
                ['Fingerprint collisions', $collisionCount],
                ['Child categories to reparent', $childCount],
                ['Mode', $this->option('apply') ? 'APPLY' : 'DRY RUN'],
            ],
        );

        if (! $this->option('apply')) {
            $this->comment('Dry run only. Re-run with --apply to perform the merge.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($gmGroups, $gmIds, $items, $opel, $hasFingerprint): void {
            foreach ($items as $item) {
                $updates = [
                    'car_group_id' => $opel->getKey(),
                    'updated_at' => now(),
                ];

                if ($hasFingerprint) {
                    $targetFingerprint = ItemAssayFingerprint::make(
                        $opel->getKey(),
                        $item->normalized_serial ?: Item::normalizeSerialValue($item->serial_code),
                        $item->weight_kg,
                        $item->pt_ppm,
                        $item->pd_ppm,
                        $item->rh_ppm,
                    );

                    $collision = $targetFingerprint !== null
                        && Item::query()
                            ->where('assay_fingerprint', $targetFingerprint)
                            ->whereKeyNot($item->getKey())
                            ->exists();

                    $updates['assay_fingerprint'] = $collision ? null : $targetFingerprint;
                }

                DB::table('items')
                    ->where('id', $item->getKey())
                    ->update($updates);
            }

            CarGroup::query()
                ->whereIn('parent_id', $gmIds)
                ->whereKeyNot($opel->getKey())
                ->update([
                    'parent_id' => $opel->getKey(),
                    'updated_at' => now(),
                ]);

            foreach ($gmGroups as $gmGroup) {
                $gmGroup->delete();
            }
        });

        $this->info(sprintf(
            'GM → OPEL merge completed: %d item(s) moved, %d collision fingerprint(s) cleared, %d GM categor%s removed.',
            $items->count(),
            $collisionCount,
            $gmGroups->count(),
            $gmGroups->count() === 1 ? 'y' : 'ies',
        ));

        return self::SUCCESS;
    }

    /** @return Collection<int, CarGroup> */
    private function gmGroups(): Collection
    {
        return CarGroup::query()
            ->where(function ($query): void {
                $query->whereRaw('UPPER(TRIM(name)) = ?', ['GM'])
                    ->orWhereRaw('UPPER(TRIM(excel_sheet_name)) = ?', ['GM']);
            })
            ->get();
    }

    private function opelGroup(): ?CarGroup
    {
        return CarGroup::query()
            ->where(function ($query): void {
                $query->whereRaw('UPPER(TRIM(name)) = ?', ['OPEL'])
                    ->orWhereRaw('UPPER(TRIM(excel_sheet_name)) = ?', ['OPEL']);
            })
            ->first();
    }

    /**
     * @param  Collection<int, Item>  $items
     * @param  array<int, string>  $gmIds
     */
    private function countTargetFingerprintCollisions(
        Collection $items,
        CarGroup $opel,
        array $gmIds,
    ): int {
        $existing = Item::query()
            ->whereNotIn('car_group_id', $gmIds)
            ->whereNotNull('assay_fingerprint')
            ->pluck('assay_fingerprint')
            ->filter()
            ->mapWithKeys(fn (string $fingerprint): array => [$fingerprint => true])
            ->all();

        $collisions = 0;

        foreach ($items as $item) {
            $fingerprint = ItemAssayFingerprint::make(
                $opel->getKey(),
                $item->normalized_serial ?: Item::normalizeSerialValue($item->serial_code),
                $item->weight_kg,
                $item->pt_ppm,
                $item->pd_ppm,
                $item->rh_ppm,
            );

            if ($fingerprint === null) {
                continue;
            }

            if (isset($existing[$fingerprint])) {
                $collisions++;
                continue;
            }

            $existing[$fingerprint] = true;
        }

        return $collisions;
    }
}
