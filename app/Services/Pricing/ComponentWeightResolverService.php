<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\Item;
use App\Models\ItemFilterMapping;
use Illuminate\Support\Str;

final class ComponentWeightResolverService
{
    public const string CONFIDENCE_HIGH = 'high';

    public const string CONFIDENCE_MEDIUM = 'medium';

    public const string CONFIDENCE_LOW = 'low';

    /** @var array<string, list<string>> */
    private const array COMBINED_TARGET_FAMILIES = [
        'BMW' => ['7800704', '7800705', '7805077', '7805091', '7805092', '7805093', '7810141', '7810169'],
        'MERCEDES' => ['KT1200', 'KT6044'],
    ];

    /** @return array<string, list<string>> */
    public function targetFamilies(): array
    {
        return self::COMBINED_TARGET_FAMILIES;
    }

    public function isTargetFamily(Item $item): bool
    {
        $group = Str::upper(trim((string) $item->carGroup?->name));
        $serial = Item::normalizeSerialValue($item->serial_code);

        return in_array($serial, self::COMBINED_TARGET_FAMILIES[$group] ?? [], true);
    }

    /** @return array{resolved:bool,weight_kg:?float,confidence:string,component_type:string,reason:string,evidence:array<string,mixed>} */
    public function resolve(Item $item, ItemFilterMapping $mapping): array
    {
        $evidence = is_array($mapping->evidence) ? $mapping->evidence : [];

        if (! $this->isTargetFamily($item)) {
            return $this->unresolved('not_combined_target_family', 'not_applicable', $evidence);
        }

        $text = Str::upper(implode(' ', [
            (string) $item->serial_code,
            (string) $item->details,
            (string) $item->model,
            (string) $item->shape_code,
        ]));

        if ($this->isMetallicOrAmbiguous($text)) {
            return $this->unresolved('metallic_or_ambiguous_component', 'metallic_or_ambiguous', $evidence);
        }

        $explicit = $this->explicitEvidence($item, $evidence);
        if ($explicit !== null) {
            return $explicit;
        }

        $filter = $mapping->filterItem;
        $itemSerial = Item::normalizeSerialValue($item->serial_code);
        $filterSerial = Item::normalizeSerialValue($mapping->filter_serial ?: $filter?->serial_code);

        if ($filter instanceof Item
            && $filterSerial !== ''
            && $filterSerial === $itemSerial
            && (float) $filter->weight_kg > 0
            && $this->looksLikeStandaloneFilter($filter)) {
            return $this->resolved((float) $filter->weight_kg, 'dpf', 'exact_local_filter_sample', [
                'family_key' => $item->serial_code,
                'source_item_id' => (string) $filter->getKey(),
                'source_serial' => $filter->serial_code,
            ]);
        }

        return $this->unresolved(
            $filter instanceof Item ? 'local_sample_not_verified_filter_component' : 'missing_trusted_component_weight',
            'unknown',
            $evidence,
        );
    }

    /** @param array<string,mixed> $record
     * @return array{resolved:bool,weight_kg:?float,confidence:string,component_type:string,reason:string,evidence:array<string,mixed>}
     */
    public function resolveEvidenceRecord(Item $item, array $record): array
    {
        if (! $this->isTargetFamily($item)) {
            return $this->unresolved('not_combined_target_family', 'not_applicable', $record);
        }

        $family = Item::normalizeSerialValue((string) ($record['family_key'] ?? $record['serial'] ?? ''));
        $serial = Item::normalizeSerialValue($item->serial_code);
        $confidence = Str::lower((string) ($record['confidence'] ?? ''));
        $componentType = Str::upper((string) ($record['component_type'] ?? ''));
        $weight = $record['weight_kg'] ?? null;

        if ($family === '' || $family !== $serial) {
            return $this->unresolved('evidence_family_mismatch', 'unknown', $record);
        }

        if ($confidence !== self::CONFIDENCE_HIGH) {
            return $this->unresolved('evidence_not_high_confidence', Str::lower($componentType ?: 'unknown'), $record);
        }

        if (in_array($componentType, ['METALLIC', 'HYBRID', 'UNKNOWN'], true)) {
            return $this->unresolved('non_dpf_component_weight', Str::lower($componentType), $record);
        }

        if (! in_array($componentType, ['DPF', 'FILTER'], true) || ! is_numeric($weight) || (float) $weight <= 0) {
            return $this->unresolved('missing_high_confidence_dpf_weight', Str::lower($componentType ?: 'unknown'), $record);
        }

        return $this->resolved((float) $weight, 'dpf', 'high_confidence_evidence_file', $record);
    }

    public function isHighConfidence(array $resolution): bool
    {
        return ($resolution['resolved'] ?? false) === true
            && ($resolution['confidence'] ?? null) === self::CONFIDENCE_HIGH
            && is_numeric($resolution['weight_kg'] ?? null)
            && (float) $resolution['weight_kg'] > 0;
    }

    /** @param array<string,mixed> $evidence
     * @return array{resolved:bool,weight_kg:?float,confidence:string,component_type:string,reason:string,evidence:array<string,mixed>}|null
     */
    private function explicitEvidence(Item $item, array $evidence): ?array
    {
        $family = Item::normalizeSerialValue((string) ($evidence['family_key'] ?? $evidence['serial'] ?? $evidence['variant_key'] ?? $evidence['oem'] ?? ''));
        $itemSerial = Item::normalizeSerialValue($item->serial_code);
        $confidence = Str::lower((string) ($evidence['confidence'] ?? ''));
        $componentType = Str::upper((string) ($evidence['component_type'] ?? ''));
        $sourceWeight = $evidence['source_weight_kg'] ?? null;

        if ($family !== '' && $family === $itemSerial
            && in_array($confidence, [self::CONFIDENCE_HIGH, 'manual'], true)
            && in_array($componentType, ['DPF', 'FILTER'], true)
            && is_numeric($sourceWeight) && (float) $sourceWeight > 0) {
            return $this->resolved((float) $sourceWeight, 'dpf', (string) ($evidence['weight_source'] ?? 'trusted_external_source'), $evidence);
        }

        $combined = $evidence['combined_weight_kg'] ?? null;
        $ceramic = $evidence['ceramic_weight_kg'] ?? null;

        if ($family !== '' && $family === $itemSerial
            && in_array($confidence, [self::CONFIDENCE_HIGH, 'manual'], true)
            && is_numeric($combined) && is_numeric($ceramic)
            && (float) $combined > (float) $ceramic) {
            return $this->resolved((float) $combined - (float) $ceramic, 'dpf', 'combined_minus_ceramic', $evidence);
        }

        return null;
    }

    private function looksLikeStandaloneFilter(Item $item): bool
    {
        $text = Str::upper(implode(' ', [
            (string) $item->serial_code,
            (string) $item->details,
            (string) $item->model,
            (string) $item->shape_code,
        ]));

        $hasFilter = preg_match('/FILTER|FILTRAS|DPF|\\bPF\\s*\\d+/u', $text) === 1;
        $isCombined = preg_match('/FILTER\\s*\\+\\s*KAT|KAT\\s*\\+\\s*FILTER|FILTRAS\\s*\\+\\s*(KERAMIKA|METALAS)|CERAMIC\\s*\\+\\s*DPF|SU\\s+FILTRU/u', $text) === 1;
        $isCatalyst = preg_match('/KATALIST|CATALYST|CERAMIC/u', $text) === 1;

        return $hasFilter && ! $isCombined && ! $isCatalyst;
    }

    private function isMetallicOrAmbiguous(string $text): bool
    {
        return preg_match('/METAL|METALLIC|AMBIG|METALAS/u', $text) === 1;
    }

    /** @param array<string,mixed> $extra
     * @return array{resolved:bool,weight_kg:?float,confidence:string,component_type:string,reason:string,evidence:array<string,mixed>}
     */
    private function resolved(float $weight, string $componentType, string $source, array $extra): array
    {
        return [
            'resolved' => true,
            'weight_kg' => round($weight, 4),
            'confidence' => self::CONFIDENCE_HIGH,
            'component_type' => $componentType,
            'reason' => $source,
            'evidence' => [
                ...$extra,
                'component_type' => $componentType,
                'weight_source' => $source,
                'source_weight_kg' => round($weight, 4),
                'confidence' => self::CONFIDENCE_HIGH,
                'observed_at' => now()->toIso8601String(),
            ],
        ];
    }

    /** @param array<string,mixed> $evidence
     * @return array{resolved:bool,weight_kg:?float,confidence:string,component_type:string,reason:string,evidence:array<string,mixed>}
     */
    private function unresolved(string $reason, string $componentType, array $evidence): array
    {
        return [
            'resolved' => false,
            'weight_kg' => null,
            'confidence' => self::CONFIDENCE_LOW,
            'component_type' => $componentType,
            'reason' => $reason,
            'evidence' => $evidence,
        ];
    }
}
