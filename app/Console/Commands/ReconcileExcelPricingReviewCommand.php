<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Services\Pricing\ExcelPricingReviewEvidenceService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ReconcileExcelPricingReviewCommand extends Command
{
    protected $signature = 'pricing:reconcile-excel-review
        {--apply : Persist the reviewed Excel findings}';

    protected $description = 'Apply verified Excel review findings to pricing-review classifications without altering original item assays.';

    public function handle(ExcelPricingReviewEvidenceService $reviewService): int
    {
        $items = $this->kt1200Items()->get();
        $eligible = $items->filter(function (Item $item): bool {
            $status = $item->filterMapping?->status;

            return ! in_array($status, [
                ItemFilterMapping::STATUS_APPROVED,
                ItemFilterMapping::STATUS_IGNORED,
            ], true);
        });

        if (! (bool) $this->option('apply')) {
            $this->info(sprintf(
                'Dry run: %d KT 1200 item(s) require assay/source review reconciliation.',
                $eligible->count(),
            ));
            $this->line('No database rows were changed. Re-run with --apply to persist.');

            return self::SUCCESS;
        }

        $updated = DB::transaction(function () use ($eligible, $reviewService): int {
            $count = 0;

            foreach ($eligible as $item) {
                $review = $reviewService->reviewFor($item);
                if ($review === null) {
                    continue;
                }

                $mapping = ItemFilterMapping::query()->firstOrNew([
                    'item_id' => $item->getKey(),
                ]);
                $mapping->setRelation('item', $item);

                $reviewService->applyToMapping($mapping, $review);
                $count++;
            }

            return $count;
        });

        $this->info("Reconciled {$updated} KT 1200 item(s) as assay/source review.");
        $this->line('No item weight or Pt/Pd/Rh assay value was changed.');

        return self::SUCCESS;
    }

    private function kt1200Items(): Builder
    {
        return Item::query()
            ->with(['carGroup', 'filterMapping'])
            ->where('normalized_serial', 'KT1200')
            ->whereHas(
                'carGroup',
                fn (Builder $query): Builder => $query->where('name', 'Mercedes'),
            )
            ->orderBy('id');
    }
}
