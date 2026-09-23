<?php

namespace App\Console\Commands;

use App\Models\CarGroup;
use Illuminate\Console\Command;

class DeleteEmptyCarGroupsCommand extends Command
{
    protected $signature = 'car-groups:delete-empty
        {--apply : Permanently delete car groups that do not contain any items. Without this option the command is a dry run.}';

    protected $description = 'Delete car groups that have no items';

    public function handle(): int
    {
        $emptyGroups = CarGroup::query()
            ->doesntHave('items')
            ->orderBy('name');

        $count = (clone $emptyGroups)->count();

        if ($count === 0) {
            $this->info('No empty car groups found.');

            return self::SUCCESS;
        }

        $this->info("Found {$count} car group(s) without items.");

        if (! $this->option('apply')) {
            $this->table(
                ['Name'],
                (clone $emptyGroups)
                    ->limit(50)
                    ->pluck('name')
                    ->map(fn (string $name): array => [$name])
                    ->all(),
            );

            $this->warn('Dry run only. Re-run with --apply to delete these car groups.');

            return self::SUCCESS;
        }

        $ids = $emptyGroups->pluck('id');
        $deleted = 0;

        foreach ($ids as $id) {
            $group = CarGroup::query()->find($id);

            if (! $group || $group->items()->exists()) {
                continue;
            }

            $group->delete();
            $deleted++;
        }

        $this->info("Deleted {$deleted} empty car group(s).");

        return self::SUCCESS;
    }
}
