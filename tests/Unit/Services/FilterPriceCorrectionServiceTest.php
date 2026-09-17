<?php

use App\Models\CarGroup;
use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Services\Pricing\FilterPriceCorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('weight correction uses the median filter weight and never mutates the item', function (): void {
    $group = CarGroup::factory()->create(['name' => 'MERCEDES']);
    $product = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'KT 6043',
        'weight_kg' => 3.0,
        'pt_ppm' => 1000,
        'pd_ppm' => 500,
        'rh_ppm' => 100,
        'details' => 'FILTER + KAT',
    ]);

    foreach ([2.0, 2.2, 5.0] as $weight) {
        Item::factory()->create([
            'car_group_id' => $group->id,
            'serial_code' => 'KT 6043',
            'weight_kg' => $weight,
            'pt_ppm' => 100,
            'pd_ppm' => 50,
            'rh_ppm' => 10,
            'details' => 'FILTER',
        ]);
    }

    $mapping = ItemFilterMapping::query()->create([
        'item_id' => $product->id,
        'filter_serial' => 'KT 6043',
        'status' => ItemFilterMapping::STATUS_APPROVED,
        'confidence' => 'high',
        'detection_method' => 'same_serial_filter_sibling',
    ]);

    $service = app(FilterPriceCorrectionService::class);
    $profile = $service->filterProfile($product, $mapping);
    $assay = $service->effectiveAssay($product, FilterPriceCorrectionService::MODE_WEIGHT_ONLY);

    expect($profile['weight_kg'])->toBe(2.2)
        ->and($profile['sample_count'])->toBe(3)
        ->and($assay['applied'])->toBeTrue()
        ->and($assay['weight_kg'])->toBe(0.8)
        ->and($assay['pt_ppm'])->toBe(1000.0)
        ->and((float) $product->fresh()->weight_kg)->toBe(3.0)
        ->and((float) $product->fresh()->pt_ppm)->toBe(1000.0);
});

test('weight and metals mode subtracts physical metal mass before recomputing ppm', function (): void {
    $group = CarGroup::factory()->create(['name' => 'PSA']);
    $product = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'K216-COMBINED',
        'weight_kg' => 3.0,
        'pt_ppm' => 1000,
        'pd_ppm' => 500,
        'rh_ppm' => 100,
        'details' => 'Su filtru',
    ]);
    $filter = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'F005',
        'weight_kg' => 1.0,
        'pt_ppm' => 100,
        'pd_ppm' => 50,
        'rh_ppm' => 10,
        'details' => 'DPF FILTER',
    ]);
    $mapping = ItemFilterMapping::query()->create([
        'item_id' => $product->id,
        'filter_item_id' => $filter->id,
        'filter_serial' => 'F005',
        'status' => ItemFilterMapping::STATUS_APPROVED,
        'confidence' => 'manual',
        'detection_method' => 'manual',
    ]);

    $assay = app(FilterPriceCorrectionService::class)
        ->effectiveAssay($product, FilterPriceCorrectionService::MODE_WEIGHT_AND_METALS);

    expect($assay['applied_mode'])->toBe(FilterPriceCorrectionService::MODE_WEIGHT_AND_METALS)
        ->and($assay['weight_kg'])->toBe(2.0)
        ->and($assay['pt_ppm'])->toBe(1450.0)
        ->and($assay['pd_ppm'])->toBe(725.0)
        ->and($assay['rh_ppm'])->toBe(145.0);
});

test('detected mappings can be previewed but do not affect runtime pricing until approved', function (): void {
    $group = CarGroup::factory()->create(['name' => 'BMW']);
    $product = Item::factory()->create([
        'car_group_id' => $group->id,
        'weight_kg' => 3.0,
        'pt_ppm' => 1000,
        'details' => 'FILTRAS + KERAMIKA',
    ]);
    $filter = Item::factory()->create([
        'car_group_id' => $group->id,
        'weight_kg' => 1.0,
        'pt_ppm' => 0,
        'pd_ppm' => 0,
        'rh_ppm' => 0,
        'details' => 'FILTRAS',
    ]);
    $mapping = ItemFilterMapping::query()->create([
        'item_id' => $product->id,
        'filter_item_id' => $filter->id,
        'filter_serial' => $filter->serial_code,
        'status' => ItemFilterMapping::STATUS_DETECTED,
        'confidence' => 'medium',
        'detection_method' => 'combined_filter_description',
    ]);

    $service = app(FilterPriceCorrectionService::class);
    $runtime = $service->effectiveAssay($product, FilterPriceCorrectionService::MODE_WEIGHT_ONLY);
    $preview = $service->effectiveAssayForMapping($product, $mapping, FilterPriceCorrectionService::MODE_WEIGHT_ONLY);

    expect($runtime['applied'])->toBeFalse()
        ->and($runtime['weight_kg'])->toBe(3.0)
        ->and($preview['applied'])->toBeTrue()
        ->and($preview['weight_kg'])->toBe(2.0);
});

test('weight and metals mode safely falls back to weight only when filter metals are unavailable', function (): void {
    $group = CarGroup::factory()->create(['name' => 'BMW']);
    $product = Item::factory()->create([
        'car_group_id' => $group->id,
        'weight_kg' => 2.5,
        'pt_ppm' => 900,
        'pd_ppm' => 300,
        'rh_ppm' => 80,
    ]);
    $filter = Item::factory()->create([
        'car_group_id' => $group->id,
        'weight_kg' => 1.0,
        'pt_ppm' => 0,
        'pd_ppm' => 0,
        'rh_ppm' => 0,
        'details' => 'FILTER',
    ]);
    ItemFilterMapping::query()->create([
        'item_id' => $product->id,
        'filter_item_id' => $filter->id,
        'filter_serial' => $filter->serial_code,
        'status' => ItemFilterMapping::STATUS_APPROVED,
        'confidence' => 'manual',
        'detection_method' => 'manual',
    ]);

    $assay = app(FilterPriceCorrectionService::class)
        ->effectiveAssay($product, FilterPriceCorrectionService::MODE_WEIGHT_AND_METALS);

    expect($assay['applied'])->toBeTrue()
        ->and($assay['applied_mode'])->toBe(FilterPriceCorrectionService::MODE_WEIGHT_ONLY)
        ->and($assay['reason'])->toBe('metal_profile_unavailable_fell_back_to_weight_only')
        ->and($assay['weight_kg'])->toBe(1.5);
});

test('correction rejects implausible net weights instead of near zero prices', function (): void {
    $group = CarGroup::factory()->create(['name' => 'MERCEDES']);
    $product = Item::factory()->create(['car_group_id' => $group->id, 'weight_kg' => 2.115, 'pt_ppm' => 1000]);
    $filter = Item::factory()->create(['car_group_id' => $group->id, 'weight_kg' => 2.1125, 'pt_ppm' => 100, 'details' => 'FILTER']);
    $mapping = ItemFilterMapping::query()->create(['item_id' => $product->id, 'filter_item_id' => $filter->id, 'filter_serial' => $filter->serial_code, 'status' => ItemFilterMapping::STATUS_APPROVED, 'confidence' => 'manual', 'detection_method' => 'manual']);

    $assay = app(FilterPriceCorrectionService::class)->effectiveAssayForMapping($product, $mapping, FilterPriceCorrectionService::MODE_WEIGHT_ONLY);

    expect($assay['applied'])->toBeFalse()->and($assay['reason'])->toBe('implausible_net_weight')->and($assay['weight_kg'])->toBe(2.115);
});

test('weight and metals mode falls back when filter metal mass exceeds the combined assay', function (): void {
    $group = CarGroup::factory()->create(['name' => 'MERCEDES']);
    $product = Item::factory()->create(['car_group_id' => $group->id, 'weight_kg' => 3.0, 'pt_ppm' => 100, 'pd_ppm' => 50, 'rh_ppm' => 10]);
    $filter = Item::factory()->create(['car_group_id' => $group->id, 'weight_kg' => 2.0, 'pt_ppm' => 1000, 'pd_ppm' => 500, 'rh_ppm' => 100, 'details' => 'FILTER']);
    $mapping = ItemFilterMapping::query()->create(['item_id' => $product->id, 'filter_item_id' => $filter->id, 'filter_serial' => $filter->serial_code, 'status' => ItemFilterMapping::STATUS_APPROVED, 'confidence' => 'manual', 'detection_method' => 'manual']);

    $assay = app(FilterPriceCorrectionService::class)->effectiveAssayForMapping($product, $mapping, FilterPriceCorrectionService::MODE_WEIGHT_AND_METALS);

    expect($assay['applied'])->toBeTrue()
        ->and($assay['applied_mode'])->toBe(FilterPriceCorrectionService::MODE_WEIGHT_ONLY)
        ->and($assay['reason'])->toBe('metal_profile_incompatible_fell_back_to_weight_only')
        ->and($assay['weight_kg'])->toBe(1.0)
        ->and($assay['pt_ppm'])->toBe(100.0);
});
