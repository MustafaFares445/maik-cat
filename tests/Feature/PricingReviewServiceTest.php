<?php

use App\Models\CarGroup;
use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Services\Mobile\MetalsSpotService;
use App\Services\Pricing\PricingReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $mock = Mockery::mock(MetalsSpotService::class);
    $mock->shouldReceive('all')->andReturn([
        'source' => 'test',
        'cached' => false,
        'currency' => 'USD',
        'fx_rate' => 1.0,
        'updated_at' => now()->toIso8601String(),
        'data' => [
            ['key' => 'platinum', 'price_oz' => 10.0 * 31.1043, 'price_gram' => 10.0],
            ['key' => 'palladium', 'price_oz' => 20.0 * 31.1043, 'price_gram' => 20.0],
            ['key' => 'rhodium', 'price_oz' => 30.0 * 31.1043, 'price_gram' => 30.0],
        ],
    ]);

    app()->instance(MetalsSpotService::class, $mock);
});

test('family preview and approval applies one verified component weight to every linked review item', function (): void {
    $group = CarGroup::factory()->create(['name' => 'Mercedes']);

    $first = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'KT 6044',
        'weight_kg' => 3.0,
        'pt_ppm' => 100,
        'pd_ppm' => 20,
        'rh_ppm' => 5,
    ]);
    $second = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'KT6044',
        'weight_kg' => 2.8,
        'pt_ppm' => 120,
        'pd_ppm' => 25,
        'rh_ppm' => 4,
    ]);

    $firstMapping = ItemFilterMapping::factory()->create([
        'item_id' => $first->id,
        'status' => ItemFilterMapping::STATUS_NEEDS_REVIEW,
        'evidence' => ['pricing_review' => ['type' => 'component_weight']],
    ]);
    $secondMapping = ItemFilterMapping::factory()->create([
        'item_id' => $second->id,
        'status' => ItemFilterMapping::STATUS_NEEDS_REVIEW,
        'evidence' => ['pricing_review' => ['type' => 'component_weight']],
    ]);

    $service = app(PricingReviewService::class);
    $preview = $service->previewFamilyWeight($firstMapping, 1.2);

    expect($preview['all_safe'])->toBeTrue()
        ->and($preview['count'])->toBe(2)
        ->and($preview['rows'][0]['proposed_price_usd'])->toBeLessThan($preview['rows'][0]['current_price_usd'])
        ->and($preview['rows'][1]['proposed_price_usd'])->toBeLessThan($preview['rows'][1]['current_price_usd']);

    $result = $service->applyFamilyWeight($firstMapping, 1.2, null, 'Measured DPF component');

    expect($result['applied'])->toBe(2)
        ->and($firstMapping->fresh()->status)->toBe(ItemFilterMapping::STATUS_APPROVED)
        ->and($secondMapping->fresh()->status)->toBe(ItemFilterMapping::STATUS_APPROVED)
        ->and((float) $firstMapping->fresh()->filter_weight_override)->toBe(1.2)
        ->and((float) $secondMapping->fresh()->filter_weight_override)->toBe(1.2)
        ->and($firstMapping->fresh()->evidence['manual_review_resolution']['type'])->toBe('component_weight');
});

test('family approval is atomic when a proposed weight is unsafe for one linked item', function (): void {
    $group = CarGroup::factory()->create(['name' => 'Mercedes']);

    $first = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'KT 1200',
        'weight_kg' => 3.0,
        'pt_ppm' => 100,
    ]);
    $second = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'KT1200',
        'weight_kg' => 1.0,
        'pt_ppm' => 100,
    ]);

    $firstMapping = ItemFilterMapping::factory()->create([
        'item_id' => $first->id,
        'status' => ItemFilterMapping::STATUS_NEEDS_REVIEW,
    ]);
    $secondMapping = ItemFilterMapping::factory()->create([
        'item_id' => $second->id,
        'status' => ItemFilterMapping::STATUS_NEEDS_REVIEW,
    ]);

    $service = app(PricingReviewService::class);
    $preview = $service->previewFamilyWeight($firstMapping, 0.95);

    expect($preview['all_safe'])->toBeFalse();

    expect(fn () => $service->applyFamilyWeight($firstMapping, 0.95, null))
        ->toThrow(RuntimeException::class);

    expect($firstMapping->fresh()->status)->toBe(ItemFilterMapping::STATUS_NEEDS_REVIEW)
        ->and($secondMapping->fresh()->status)->toBe(ItemFilterMapping::STATUS_NEEDS_REVIEW)
        ->and($firstMapping->fresh()->filter_weight_override)->toBeNull()
        ->and($secondMapping->fresh()->filter_weight_override)->toBeNull();
});

test('keeping current pricing resolves every linked review item with an audit note', function (): void {
    $group = CarGroup::factory()->create(['name' => 'BMW']);

    $first = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => '1432445',
    ]);
    $second = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => '1432-445',
    ]);

    $firstMapping = ItemFilterMapping::factory()->create([
        'item_id' => $first->id,
        'status' => ItemFilterMapping::STATUS_NEEDS_REVIEW,
    ]);
    $secondMapping = ItemFilterMapping::factory()->create([
        'item_id' => $second->id,
        'status' => ItemFilterMapping::STATUS_NEEDS_REVIEW,
    ]);

    $count = app(PricingReviewService::class)
        ->approveCurrentPricing($firstMapping, null, 'OEM and assay manually verified.');

    expect($count)->toBe(2)
        ->and($firstMapping->fresh()->status)->toBe(ItemFilterMapping::STATUS_IGNORED)
        ->and($secondMapping->fresh()->status)->toBe(ItemFilterMapping::STATUS_IGNORED)
        ->and($firstMapping->fresh()->evidence['manual_review_resolution']['type'])->toBe('keep_current_pricing')
        ->and($secondMapping->fresh()->notes)->toBe('OEM and assay manually verified.');
});


test('review issue explains the reason in user-friendly language', function (): void {
    $group = CarGroup::factory()->create(['name' => 'BMW']);

    $item = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'REVIEW-01',
        'weight_kg' => 2.5,
        'pt_ppm' => 100,
    ]);

    $mapping = ItemFilterMapping::factory()->create([
        'item_id' => $item->id,
        'filter_item_id' => null,
        'filter_serial' => null,
        'status' => ItemFilterMapping::STATUS_NEEDS_REVIEW,
        'evidence' => [
            'threshold_match' => true,
        ],
    ]);

    $issue = app(PricingReviewService::class)->issue($mapping->fresh(['item']));

    expect($issue['label'])->toBe('Weight and price need confirmation')
        ->and($issue['instruction'])->toBe('Check the item details before showing this item in the app again.');
});
