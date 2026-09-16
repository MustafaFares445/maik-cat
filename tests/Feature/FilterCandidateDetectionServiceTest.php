<?php

use App\Models\CarGroup;
use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Services\Pricing\FilterCandidateDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('detector finds combined catalyst filter families without auto approving them', function (): void {
    $group = CarGroup::factory()->create(['name' => 'MERCEDES', 'excel_sheet_name' => 'MERCEDES']);
    $product = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'KT 6043',
        'weight_kg' => 3.0,
        'pt_ppm' => 1000,
        'pd_ppm' => 500,
        'rh_ppm' => 100,
        'details' => 'FILTER + KAT',
    ]);
    $filter = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'KT 6043',
        'weight_kg' => 2.2,
        'pt_ppm' => 100,
        'pd_ppm' => 50,
        'rh_ppm' => 10,
        'details' => 'FILTER',
    ]);

    $summary = app(FilterCandidateDetectionService::class)->scan();
    $mapping = ItemFilterMapping::query()->where('item_id', $product->id)->firstOrFail();

    expect($summary['candidates'])->toBe(1)
        ->and($summary['matched'])->toBe(1)
        ->and($mapping->filter_item_id)->toBe($filter->id)
        ->and($mapping->status)->toBe(ItemFilterMapping::STATUS_DETECTED)
        ->and($mapping->confidence)->toBe('high')
        ->and(ItemFilterMapping::query()->where('item_id', $filter->id)->exists())->toBeFalse()
        ->and((float) $product->fresh()->weight_kg)->toBe(3.0);
});

test('detector treats explicit PF references as high confidence when the filter exists', function (): void {
    $group = CarGroup::factory()->create(['name' => 'MERCEDES', 'excel_sheet_name' => 'MERCEDES']);
    $filter = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'PF0030',
        'weight_kg' => 1.75,
        'pt_ppm' => 20,
        'pd_ppm' => 10,
        'rh_ppm' => 2,
        'details' => 'FILTER',
    ]);
    $product = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'KT 1243',
        'weight_kg' => 2.3,
        'pt_ppm' => 1200,
        'pd_ppm' => 300,
        'rh_ppm' => 90,
        'details' => 'KATALIST s PF0030',
    ]);

    app(FilterCandidateDetectionService::class)->scan();
    $mapping = ItemFilterMapping::query()->where('item_id', $product->id)->firstOrFail();

    expect($mapping->filter_item_id)->toBe($filter->id)
        ->and($mapping->filter_serial)->toBe('PF0030')
        ->and($mapping->detection_method)->toBe('explicit_filter_serial')
        ->and($mapping->confidence)->toBe('high');
});

test('detector never recreates decisions already approved or ignored', function (): void {
    $group = CarGroup::factory()->create(['name' => 'BMW', 'excel_sheet_name' => 'BMW']);
    $product = Item::factory()->create([
        'car_group_id' => $group->id,
        'weight_kg' => 3.0,
        'details' => 'FILTRAS + KERAMIKA',
    ]);
    ItemFilterMapping::query()->create([
        'item_id' => $product->id,
        'status' => ItemFilterMapping::STATUS_IGNORED,
        'confidence' => 'manual',
        'detection_method' => 'manual',
        'notes' => 'Client reviewed this item.',
    ]);

    app(FilterCandidateDetectionService::class)->scan();

    expect(ItemFilterMapping::query()->where('item_id', $product->id)->firstOrFail()->status)
        ->toBe(ItemFilterMapping::STATUS_IGNORED);
});
