<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Services\Pricing\ComponentWeightResolverService;
use App\Services\Pricing\FilterPriceCorrectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class ResolveComponentWeightsCommand extends Command
{
    protected $signature = 'pricing:resolve-component-weights
        {--apply : Persist HIGH-confidence resolutions}
        {--dry-run : Explicitly run without writes}
        {--evidence= : Component evidence JSON path}';

    protected $description = 'Resolve trusted DPF/component weights for known combined families without mutating original item weights or assays.';

    public function handle(ComponentWeightResolverService $resolver): int
    {
        $apply = (bool) $this->option('apply') && ! (bool) $this->option('dry-run');
        $evidencePath = (string) ($this->option('evidence') ?: storage_path('app/reports/component_weight_evidence_results.json'));
        $evidence = $this->loadEvidence($evidencePath);
        $byFamily = [];

        foreach ($evidence as $record) {
            if (! is_array($record)) {
                continue;
            }

            $family = Item::normalizeSerialValue((string) ($record['family_key'] ?? $record['serial'] ?? ''));
            if ($family !== '') {
                $byFamily[$family][] = $record;
            }
        }

        $summary = [
            'scanned' => 0,
            'targeted' => 0,
            'resolved' => 0,
            'applied' => 0,
            'unresolved' => 0,
            'safety_rejected' => 0,
            'skipped' => 0,
        ];

        ItemFilterMapping::query()
            ->whereIn('status', [
                ItemFilterMapping::STATUS_DETECTED,
                ItemFilterMapping::STATUS_NEEDS_REVIEW,
                ItemFilterMapping::STATUS_IGNORED,
            ])
            ->with(['item.carGroup', 'filterItem'])
            ->orderBy('id')
            ->chunkById(250, function ($mappings) use (&$summary, $resolver, $apply, $byFamily): void {
                foreach ($mappings as $mapping) {
                    $summary['scanned']++;

                    if ($mapping->item === null || ! $resolver->isTargetFamily($mapping->item)) {
                        $summary['skipped']++;

                        continue;
                    }

                    $summary['targeted']++;
                    $family = Item::normalizeSerialValue($mapping->item->serial_code);
                    $resolution = $this->resolveFromFileEvidence($resolver, $mapping->item, $byFamily[$family] ?? []);

                    if (! $resolver->isHighConfidence($resolution)) {
                        $resolution = $resolver->resolve($mapping->item, $mapping);
                    }

                    if (! $resolver->isHighConfidence($resolution)) {
                        $summary['unresolved']++;

                        continue;
                    }

                    $summary['resolved']++;

                    $previewMapping = $mapping->replicate(['id', 'created_at', 'updated_at']);
                    $previewMapping->filter_weight_override = $resolution['weight_kg'];
                    $assay = app(FilterPriceCorrectionService::class)->effectiveAssayForMapping(
                        $mapping->item,
                        $previewMapping,
                        FilterPriceCorrectionService::MODE_WEIGHT_ONLY,
                    );

                    if (! $assay['applied']) {
                        $summary['safety_rejected']++;

                        continue;
                    }

                    if (! $apply) {
                        continue;
                    }

                    $mapping->update([
                        'filter_weight_override' => $resolution['weight_kg'],
                        'confidence' => ComponentWeightResolverService::CONFIDENCE_HIGH,
                        'evidence' => array_merge((array) $mapping->evidence, [
                            'component_weight_resolution' => $resolution['evidence'],
                        ]),
                        'status' => ItemFilterMapping::STATUS_APPROVED,
                        'approved_by' => null,
                        'approved_at' => now(),
                    ]);
                    $summary['applied']++;
                }
            });

        $this->table(
            ['Scanned', 'Targeted', 'Resolved', 'Applied', 'Unresolved', 'Safety rejected', 'Skipped'],
            [array_values($summary)],
        );
        $this->line('Evidence: '.$evidencePath);
        $this->line($apply ? 'Safe HIGH-confidence resolutions applied.' : 'Dry run only; no mappings or item assays were changed.');

        return self::SUCCESS;
    }

    /** @return list<array<string,mixed>> */
    private function loadEvidence(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid component evidence JSON: '.$path);
        }

        return array_values($decoded);
    }

    /** @param list<array<string,mixed>> $records
     * @return array{resolved:bool,weight_kg:?float,confidence:string,component_type:string,reason:string,evidence:array<string,mixed>}
     */
    private function resolveFromFileEvidence(ComponentWeightResolverService $resolver, Item $item, array $records): array
    {
        $resolved = [];

        foreach ($records as $record) {
            $candidate = $resolver->resolveEvidenceRecord($item, $record);
            if ($resolver->isHighConfidence($candidate)) {
                $resolved[] = $candidate;
            }
        }

        if ($resolved === []) {
            return [
                'resolved' => false,
                'weight_kg' => null,
                'confidence' => ComponentWeightResolverService::CONFIDENCE_LOW,
                'component_type' => 'unknown',
                'reason' => 'no_high_confidence_file_evidence',
                'evidence' => [],
            ];
        }

        $weights = array_values(array_unique(array_map(
            static fn (array $candidate): string => number_format((float) $candidate['weight_kg'], 4, '.', ''),
            $resolved,
        )));

        if (count($weights) !== 1) {
            return [
                'resolved' => false,
                'weight_kg' => null,
                'confidence' => ComponentWeightResolverService::CONFIDENCE_LOW,
                'component_type' => 'dpf',
                'reason' => 'conflicting_high_confidence_weights',
                'evidence' => ['candidate_weights_kg' => $weights],
            ];
        }

        return $resolved[0];
    }
}
