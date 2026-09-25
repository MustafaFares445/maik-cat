<?php

use App\Filament\Resources\ItemFilterMappings\Pages\CreateItemFilterMapping;
use App\Filament\Resources\ItemFilterMappings\Pages\ListItemFilterMappings;
use App\Models\CarGroup;
use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Models\User;
use App\Services\Mobile\MetalsSpotService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('super_admin');

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

test('manual filter reference is applied immediately without a review step', function (): void {
    $group = CarGroup::factory()->create(['name' => 'Mercedes']);

    $item = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'CAT 100',
        'weight_kg' => 3.0,
        'pt_ppm' => 100,
        'pd_ppm' => 20,
        'rh_ppm' => 5,
    ]);

    $filterItem = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'DPF 100',
        'weight_kg' => 1.0,
        'pt_ppm' => 0,
        'pd_ppm' => 0,
        'rh_ppm' => 0,
    ]);

    Livewire::actingAs($this->admin)
        ->test(CreateItemFilterMapping::class)
        ->fillForm([
            'item_id' => $item->id,
            'filter_item_id' => $filterItem->id,
            'filter_weight_override' => 1.2,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $mapping = ItemFilterMapping::query()->where('item_id', $item->id)->firstOrFail();

    expect($mapping->status)->toBe(ItemFilterMapping::STATUS_APPROVED)
        ->and($mapping->detection_method)->toBe('manual')
        ->and($mapping->confidence)->toBe('manual')
        ->and($mapping->filter_item_id)->toBe($filterItem->id)
        ->and($mapping->filter_serial)->toBe('DPF 100')
        ->and((float) $mapping->filter_weight_override)->toBe(1.2)
        ->and($mapping->approved_by)->toBe($this->admin->id)
        ->and($mapping->approved_at)->not->toBeNull();
});

test('admin can preview and apply a verified family weight from the review table action', function (): void {
    $group = CarGroup::factory()->create(['name' => 'Mercedes']);

    $item = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'KT 6044',
        'weight_kg' => 3.0,
        'pt_ppm' => 100,
        'pd_ppm' => 20,
        'rh_ppm' => 5,
    ]);

    $mapping = ItemFilterMapping::factory()->create([
        'item_id' => $item->id,
        'status' => ItemFilterMapping::STATUS_NEEDS_REVIEW,
        'evidence' => [
            'pricing_review' => [
                'type' => 'component_weight',
                'instruction' => 'Measure the DPF component.',
                'variant' => 'A9064901414',
            ],
        ],
    ]);

    $action = TestAction::make('reviewAndApplyWeight')->table($mapping);

    Livewire::actingAs($this->admin)
        ->test(ListItemFilterMappings::class)
        ->assertActionVisible($action)
        ->mountAction($action)
        ->setActionData([
            'filter_weight_kg' => 1.2,
            'review_note' => 'Measured on calibrated scale.',
        ])
        ->assertActionDataSet([
            'filter_weight_kg' => 1.2,
            'review_note' => 'Measured on calibrated scale.',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $mapping->refresh();

    expect($mapping->status)->toBe(ItemFilterMapping::STATUS_APPROVED)
        ->and((float) $mapping->filter_weight_override)->toBe(1.2)
        ->and($mapping->approved_by)->toBe($this->admin->id);
});

test('review action is hidden for non component-weight review types', function (): void {
    $group = CarGroup::factory()->create(['name' => 'BMW']);

    $item = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => '1432445',
    ]);

    $mapping = ItemFilterMapping::factory()->create([
        'item_id' => $item->id,
        'status' => ItemFilterMapping::STATUS_NEEDS_REVIEW,
        'evidence' => [
            'pricing_review' => [
                'type' => 'assay_source',
                'instruction' => 'Verify assay source.',
            ],
        ],
    ]);

    Livewire::actingAs($this->admin)
        ->test(ListItemFilterMappings::class)
        ->assertActionHidden(TestAction::make('reviewAndApplyWeight')->table($mapping))
        ->assertActionVisible(TestAction::make('approveCurrentPricing')->table($mapping));
});
