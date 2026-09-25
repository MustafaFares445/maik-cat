<?php

namespace App\Console\Commands;

use App\Models\CarGroup;
use App\Models\Item;
use App\Support\Items\ItemAssayFingerprint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class NormalizeCanonicalCarGroupsCommand extends Command
{
    protected $signature = 'car-groups:normalize-canonical
        {--apply : Apply the normalization. Without this option the command is a dry run.}';

    protected $description = 'Relink every item to one of the canonical car groups and delete empty noncanonical groups';

    /** @var array<string, CarGroup> */
    private array $targets = [];

    /** @var array<string, true> */
    private array $reservedFingerprints = [];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        try {
            $canonicalNames = $this->canonicalNames();

            if ($canonicalNames === []) {
                throw new RuntimeException('No canonical car groups are configured.');
            }

            if ($apply) {
                DB::beginTransaction();
            }

            $report = $this->normalize($canonicalNames, $apply);

            if ($apply) {
                DB::commit();
            }

            $this->table(
                ['Metric', 'Count'],
                collect($report)
                    ->map(fn (int $value, string $key): array => [$key, $value])
                    ->values()
                    ->all(),
            );

            $apply
                ? $this->info('Canonical car-group normalization completed.')
                : $this->comment('Dry run completed without database changes.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($apply && DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->error('Canonical car-group normalization failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  list<string>  $canonicalNames
     * @return array<string, int>
     */
    private function normalize(array $canonicalNames, bool $apply): array
    {
        $report = [
            'canonical_groups' => count($canonicalNames),
            'items_scanned' => 0,
            'items_relinked' => 0,
            'fingerprints_rebuilt' => 0,
            'fingerprint_conflicts_preserved' => 0,
            'groups_deleted' => 0,
        ];

        $this->targets = $this->resolveTargets($canonicalNames, $apply);
        $this->reserveExistingFingerprints();

        $groups = CarGroup::query()
            ->withCount('items')
            ->orderBy('name')
            ->get();

        foreach ($groups as $group) {
            $currentCanonical = $this->canonicalNameForGroup($group);

            if ($currentCanonical !== null) {
                if ($apply) {
                    $this->normalizeTargetMetadata($group, $currentCanonical);
                }

                continue;
            }

            $targetName = $this->targetNameForLegacyGroup($group);
            $target = $this->targets[$targetName] ?? null;

            if (! $target instanceof CarGroup) {
                throw new RuntimeException('Missing canonical target group: '.$targetName);
            }

            Item::query()
                ->where('car_group_id', $group->id)
                ->orderBy('id')
                ->chunkById(250, function ($items) use ($target, $apply, &$report): void {
                    foreach ($items as $item) {
                        $report['items_scanned']++;
                        $report['items_relinked']++;

                        $fingerprint = ItemAssayFingerprint::make(
                            $target->id,
                            $item->normalized_serial ?: Item::normalizeSerialValue($item->serial_code),
                            $item->weight_kg,
                            $item->pt_ppm,
                            $item->pd_ppm,
                            $item->rh_ppm,
                        );

                        $storedFingerprint = $fingerprint;

                        if ($fingerprint !== null && isset($this->reservedFingerprints[$fingerprint])) {
                            $storedFingerprint = null;
                            $report['fingerprint_conflicts_preserved']++;
                        } elseif ($fingerprint !== null) {
                            $this->reservedFingerprints[$fingerprint] = true;
                            $report['fingerprints_rebuilt']++;
                        }

                        if (! $apply) {
                            continue;
                        }

                        DB::table('items')
                            ->where('id', $item->id)
                            ->update([
                                'car_group_id' => $target->id,
                                'assay_fingerprint' => $storedFingerprint,
                                'updated_at' => now(),
                            ]);
                    }
                }, 'id');
        }

        if ($apply) {
            $report['groups_deleted'] = $this->deleteEmptyNoncanonicalGroups($canonicalNames);
        } else {
            $report['groups_deleted'] = CarGroup::query()
                ->get()
                ->filter(fn (CarGroup $group): bool => $this->canonicalNameForGroup($group) === null)
                ->count();
        }

        return $report;
    }

    /** @param list<string> $canonicalNames @return array<string, CarGroup> */
    private function resolveTargets(array $canonicalNames, bool $apply): array
    {
        $targets = [];

        foreach ($canonicalNames as $name) {
            $group = CarGroup::query()
                ->whereRaw('UPPER(excel_sheet_name) = ?', [$name])
                ->orWhereRaw('UPPER(name) = ?', [$name])
                ->first();

            if (! $group instanceof CarGroup) {
                if (! $apply) {
                    throw new RuntimeException('Canonical car group is missing: '.$name);
                }

                $group = CarGroup::query()->create([
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'excel_sheet_name' => $name,
                    'region' => null,
                    'parent_id' => null,
                    'source' => null,
                    'source_url' => null,
                ]);
            }

            $targets[$name] = $group;
        }

        return $targets;
    }

    private function reserveExistingFingerprints(): void
    {
        $canonicalIds = collect($this->targets)->pluck('id')->all();

        Item::query()
            ->whereIn('car_group_id', $canonicalIds)
            ->whereNotNull('assay_fingerprint')
            ->pluck('assay_fingerprint')
            ->each(function (string $fingerprint): void {
                $this->reservedFingerprints[$fingerprint] = true;
            });
    }

    private function normalizeTargetMetadata(CarGroup $group, string $canonicalName): void
    {
        $group->forceFill([
            'name' => $canonicalName,
            'slug' => Str::slug($canonicalName),
            'excel_sheet_name' => $canonicalName,
            'parent_id' => null,
            'source' => null,
            'source_url' => null,
        ]);

        if ($group->isDirty()) {
            $group->save();
        }
    }

    private function canonicalNameForGroup(CarGroup $group): ?string
    {
        foreach ([$group->excel_sheet_name, $group->name] as $value) {
            $normalized = Str::upper(trim((string) $value));

            if (isset($this->targets[$normalized])) {
                return $normalized;
            }
        }

        return null;
    }

    private function targetNameForLegacyGroup(CarGroup $group): string
    {
        $slug = Str::of((string) $group->slug)
            ->trim()
            ->lower()
            ->replace('_', '-')
            ->replace(' ', '-')
            ->toString();

        $mapping = (array) config('imports.ecotrade_brand_groups', []);
        $default = Str::upper((string) config('imports.ecotrade_default_group', 'RAZNI'));
        $target = Str::upper((string) ($mapping[$slug] ?? $default));

        return isset($this->targets[$target]) ? $target : $default;
    }

    /** @param list<string> $canonicalNames */
    private function deleteEmptyNoncanonicalGroups(array $canonicalNames): int
    {
        $groups = CarGroup::query()
            ->doesntHave('items')
            ->get()
            ->filter(function (CarGroup $group) use ($canonicalNames): bool {
                $name = Str::upper(trim((string) ($group->excel_sheet_name ?: $group->name)));

                return ! in_array($name, $canonicalNames, true);
            });

        if ($groups->isEmpty()) {
            return 0;
        }

        $ids = $groups->pluck('id')->all();

        CarGroup::query()
            ->whereIn('parent_id', $ids)
            ->update(['parent_id' => null]);

        foreach ($groups as $group) {
            $group->delete();
        }

        return $groups->count();
    }

    /** @return list<string> */
    private function canonicalNames(): array
    {
        return collect((array) config('imports.canonical_car_groups', []))
            ->map(static fn (mixed $name): string => Str::upper(trim((string) $name)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
