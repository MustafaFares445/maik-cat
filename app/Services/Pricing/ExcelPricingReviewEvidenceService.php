<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\Item;
use App\Models\ItemFilterMapping;
use Illuminate\Support\Str;

final class ExcelPricingReviewEvidenceService
{
    public const string SOURCE = 'excel_workbook_review_2026_09_18';

    /** @var array<string, array<string, array<string, mixed>>> */
    private const array SOURCE_REVIEW_FAMILIES = [
        'MERCEDES' => [
            'KT1200' => [
                'variant' => 'A2114906836',
                'instruction' => 'Excel review classifies A2114906836 as KATALIST at 2.0 kg. The 1.91 kg KT 1200 row only references PF0021 and is not a standalone filter weight. Verify the exact OEM/variant and Pt/Pd/Rh assay before keeping current pricing.',
                'excel_findings' => [
                    'catalyst_weight_kg' => 2.0,
                    'rejected_component_weight_kg' => 1.91,
                    'rejected_reference' => 'PF0021',
                ],
            ],
        ],
    ];

    /** @return array<string, list<string>> */
    public function targetFamilies(): array
    {
        $families = [];

        foreach (self::SOURCE_REVIEW_FAMILIES as $group => $serials) {
            $families[$group] = array_keys($serials);
        }

        return $families;
    }

    /** @return array<string, mixed>|null */
    public function reviewFor(Item $item): ?array
    {
        $config = $this->configFor($item);
        if ($config === null) {
            return null;
        }

        return [
            'type' => 'assay_source',
            'source' => self::SOURCE,
            'variant' => $config['variant'],
            'instruction' => $config['instruction'],
            'excel_findings' => $config['excel_findings'],
            'queued_at' => now()->toIso8601String(),
        ];
    }

    public function applyToMapping(ItemFilterMapping $mapping, array $review): ItemFilterMapping
    {
        $evidence = is_array($mapping->evidence) ? $mapping->evidence : [];
        $previous = $evidence['pricing_review'] ?? null;
        if (is_array($previous) && $previous !== $review) {
            $history = is_array($evidence['pricing_review_history'] ?? null)
                ? $evidence['pricing_review_history']
                : [];
            $history[] = $previous;
            $evidence['pricing_review_history'] = $history;
        }

        $evidence['pricing_review'] = $review;

        $mapping->forceFill([
            'filter_item_id' => null,
            'filter_serial' => null,
            'filter_weight_override' => null,
            'detection_method' => 'excel_source_verification',
            'confidence' => 'medium',
            'status' => ItemFilterMapping::STATUS_NEEDS_REVIEW,
            'evidence' => $evidence,
            'notes' => (string) $review['instruction'],
            'approved_by' => null,
            'approved_at' => null,
        ])->save();

        return $mapping;
    }

    /** @return array<string, mixed>|null */
    private function configFor(Item $item): ?array
    {
        $group = Str::upper(trim((string) $item->carGroup?->name));
        $serial = Item::normalizeSerialValue($item->normalized_serial ?: $item->serial_code);

        return self::SOURCE_REVIEW_FAMILIES[$group][$serial] ?? null;
    }
}
