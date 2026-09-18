<?php

use App\Models\CarGroup;
use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Services\Pricing\ComponentWeightResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('resolver accepts an exact standalone filter sample for a target combined family', function (): void {
    $group = CarGroup::factory()->create(['name' => 'BMW']);
    $item = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => '7805077',
        'weight_kg' => 3.0,
        'details' => 'CERAMIC + DPF',
    ]);
    $filter = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => '7805-077',
        'weight_kg' => 1.25,
        'details' => 'DPF FILTER',
    ]);
    $mapping = ItemFilterMapping::factory()->create([
        'item_id' => $item->id,
        'filter_item_id' => $filter->id,
        'filter_serial' => '7805077',
    ]);

    $resolution = app(ComponentWeightResolverService::class)->resolve($item, $mapping);

    expect($resolution['resolved'])->toBeTrue()
        ->and($resolution['confidence'])->toBe(ComponentWeightResolverService::CONFIDENCE_HIGH)
        ->and($resolution['weight_kg'])->toBe(1.25)
        ->and($resolution['reason'])->toBe('exact_local_filter_sample');
});

test('resolver refuses metallic construction even for a target family', function (): void {
    $group = CarGroup::factory()->create(['name' => 'BMW']);
    $item = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => '7805077',
        'details' => 'DPF + METALLIC',
    ]);
    $filter = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => '7805077',
        'weight_kg' => 1.0,
        'details' => 'DPF FILTER',
    ]);
    $mapping = ItemFilterMapping::factory()->create([
        'item_id' => $item->id,
        'filter_item_id' => $filter->id,
        'filter_serial' => '7805077',
    ]);

    $resolution = app(ComponentWeightResolverService::class)->resolve($item, $mapping);

    expect($resolution['resolved'])->toBeFalse()
        ->and($resolution['reason'])->toBe('metallic_or_ambiguous_component');
});

test('resolver derives dpf weight only from high confidence same family evidence', function (): void {
    $group = CarGroup::factory()->create(['name' => 'Mercedes']);
    $item = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'KT6044',
    ]);
    $mapping = ItemFilterMapping::factory()->create([
        'item_id' => $item->id,
        'evidence' => [
            'family_key' => 'KT 6044',
            'variant_key' => 'A9064901414',
            'combined_weight_kg' => 3.4,
            'ceramic_weight_kg' => 2.1,
            'confidence' => 'high',
        ],
    ]);

    $resolution = app(ComponentWeightResolverService::class)->resolve($item, $mapping);

    expect($resolution['resolved'])->toBeTrue()
        ->and($resolution['weight_kg'])->toBe(1.3)
        ->and($resolution['reason'])->toBe('combined_minus_ceramic');
});

test('resolver accepts high confidence dpf evidence file record with composite variant key', function (): void {
    $group = CarGroup::factory()->create(['name' => 'BMW']);
    $item = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => '7805092',
    ]);

    $resolution = app(ComponentWeightResolverService::class)->resolveEvidenceRecord($item, [
        'family_key' => '7805092',
        'variant_key' => '7805092 7805145 DPF',
        'component_type' => 'DPF',
        'weight_kg' => 1.42,
        'weight_kind' => 'DIRECT',
        'confidence' => 'HIGH',
        'source_url' => 'https://example.test/dpf',
    ]);

    expect($resolution['resolved'])->toBeTrue()
        ->and($resolution['weight_kg'])->toBe(1.42)
        ->and($resolution['reason'])->toBe('high_confidence_evidence_file');
});

test('resolver rejects high confidence metallic mass as a filter weight', function (): void {
    $group = CarGroup::factory()->create(['name' => 'BMW']);
    $item = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => '7805092',
    ]);

    $resolution = app(ComponentWeightResolverService::class)->resolveEvidenceRecord($item, [
        'family_key' => '7805092',
        'component_type' => 'METALLIC',
        'weight_kg' => 0.9,
        'confidence' => 'HIGH',
    ]);

    expect($resolution['resolved'])->toBeFalse()
        ->and($resolution['reason'])->toBe('non_dpf_component_weight');
});

test('resolver refuses non target family even when an exact local filter sample exists', function (): void {
    $group = CarGroup::factory()->create(['name' => 'BMW']);
    $item = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => '3423937',
        'details' => 'DPF',
    ]);
    $filter = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => '3423937',
        'weight_kg' => 4.0,
        'details' => 'DPF FILTER',
    ]);
    $mapping = ItemFilterMapping::factory()->create([
        'item_id' => $item->id,
        'filter_item_id' => $filter->id,
        'filter_serial' => '3423937',
    ]);

    $resolution = app(ComponentWeightResolverService::class)->resolve($item, $mapping);

    expect($resolution['resolved'])->toBeFalse()
        ->and($resolution['reason'])->toBe('not_combined_target_family');
});
