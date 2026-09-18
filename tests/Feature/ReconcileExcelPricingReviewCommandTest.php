<?php

use App\Models\CarGroup;
use App\Models\Item;
use App\Models\ItemFilterMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('excel pricing reconciliation moves KT 1200 out of component weight review', function (): void {
    $group = CarGroup::factory()->create(['name' => 'Mercedes']);
    $referenceRow = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'KT 1200',
        'weight_kg' => 1.91,
        'details' => 's PF0021',
    ]);
    $item = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'KT1200',
        'weight_kg' => 2.0,
        'pt_ppm' => 8820,
        'pd_ppm' => 0,
        'rh_ppm' => 0,
        'model' => 'KATALIST',
        'details' => 'A 211 490 68 36',
    ]);
    $mapping = ItemFilterMapping::factory()->create([
        'item_id' => $item->id,
        'filter_item_id' => $referenceRow->id,
        'filter_serial' => 'KT 1200',
        'filter_weight_override' => 1.91,
        'status' => ItemFilterMapping::STATUS_NEEDS_REVIEW,
        'confidence' => 'medium',
        'detection_method' => 'combined_filter_description',
        'evidence' => [
            'pricing_review' => [
                'type' => 'component_weight',
                'source' => 'manual_measurement_candidates',
            ],
        ],
    ]);

    $this->artisan('pricing:reconcile-excel-review', ['--apply' => true])
        ->expectsOutputToContain('Reconciled 2 KT 1200 item(s) as assay/source review.')
        ->assertExitCode(0);

    $mapping->refresh();
    $item->refresh();

    expect($mapping->status)->toBe(ItemFilterMapping::STATUS_NEEDS_REVIEW)
        ->and($mapping->filter_item_id)->toBeNull()
        ->and($mapping->filter_serial)->toBeNull()
        ->and($mapping->filter_weight_override)->toBeNull()
        ->and($mapping->detection_method)->toBe('excel_source_verification')
        ->and($mapping->evidence['pricing_review']['type'])->toBe('assay_source')
        ->and($mapping->evidence['pricing_review']['source'])->toBe('excel_workbook_review_2026_09_18')
        ->and($mapping->evidence['pricing_review_history'][0]['type'])->toBe('component_weight')
        ->and((float) $item->weight_kg)->toBe(2.0)
        ->and((float) $item->pt_ppm)->toBe(8820.0)
        ->and((float) $item->pd_ppm)->toBe(0.0)
        ->and((float) $item->rh_ppm)->toBe(0.0);

    $referenceMapping = ItemFilterMapping::query()->where('item_id', $referenceRow->id)->firstOrFail();

    expect($referenceMapping->status)->toBe(ItemFilterMapping::STATUS_NEEDS_REVIEW)
        ->and($referenceMapping->filter_item_id)->toBeNull()
        ->and($referenceMapping->evidence['pricing_review']['type'])->toBe('assay_source');
});

test('excel pricing reconciliation dry run does not mutate KT 1200 mappings', function (): void {
    $group = CarGroup::factory()->create(['name' => 'Mercedes']);
    $item = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'KT 1200',
    ]);
    $mapping = ItemFilterMapping::factory()->create([
        'item_id' => $item->id,
        'status' => ItemFilterMapping::STATUS_NEEDS_REVIEW,
        'evidence' => ['pricing_review' => ['type' => 'component_weight']],
    ]);

    $this->artisan('pricing:reconcile-excel-review')
        ->expectsOutputToContain('Dry run: 1 KT 1200 item(s) require assay/source review reconciliation.')
        ->assertExitCode(0);

    expect($mapping->fresh()->evidence['pricing_review']['type'])->toBe('component_weight');
});
