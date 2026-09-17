<?php

namespace App\Console\Commands;

use App\Services\Pricing\FilterCandidateDetectionService;
use Illuminate\Console\Command;

class DetectFilterPricingCandidatesCommand extends Command
{
    protected $signature = 'pricing:detect-filter-candidates
        {--reset-unreviewed : Remove prior detected/needs-review mappings before scanning}';

    protected $description = 'Detect BMW, Mercedes and PSA items that may include filter weight in catalyst pricing.';

    public function handle(FilterCandidateDetectionService $detector): int
    {
        if ((bool) $this->option('reset-unreviewed')) {
            $this->line('Cleared unreviewed mappings: '.$detector->clearUnreviewed());
        }

        $summary = $detector->scan();

        $this->table(
            ['Scanned', 'Candidates', 'Matched filter', 'Needs review', 'Skipped'],
            [[
                $summary['scanned'],
                $summary['candidates'],
                $summary['matched'],
                $summary['needs_review'],
                $summary['skipped'],
            ]],
        );

        $this->info('Detection completed. No item assay values were changed.');

        return self::SUCCESS;
    }
}
