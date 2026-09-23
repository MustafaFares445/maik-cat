<?php

use App\Models\CarGroup;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('delete empty car groups command is dry run by default and deletes only empty groups when applied', function (): void {
    $emptyGroup = CarGroup::factory()->create([
        'name' => 'EMPTY GROUP',
        'excel_sheet_name' => 'EMPTY GROUP',
    ]);

    $populatedGroup = CarGroup::factory()->create([
        'name' => 'POPULATED GROUP',
        'excel_sheet_name' => 'POPULATED GROUP',
    ]);

    Item::factory()->create([
        'car_group_id' => $populatedGroup->id,
    ]);

    expect(Artisan::call('car-groups:delete-empty'))->toBe(0)
        ->and(CarGroup::query()->whereKey($emptyGroup->id)->exists())->toBeTrue()
        ->and(CarGroup::query()->whereKey($populatedGroup->id)->exists())->toBeTrue();

    expect(Artisan::call('car-groups:delete-empty', ['--apply' => true]))->toBe(0)
        ->and(CarGroup::query()->whereKey($emptyGroup->id)->exists())->toBeFalse()
        ->and(CarGroup::query()->whereKey($populatedGroup->id)->exists())->toBeTrue();
});
