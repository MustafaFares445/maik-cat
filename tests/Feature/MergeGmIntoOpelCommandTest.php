<?php

use App\Models\CarGroup;
use App\Models\Item;
use App\Services\ImportSheetGroupResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('gm to opel command is dry-run by default and safely applies the merge', function (): void {
    $opel = CarGroup::factory()->create([
        'name' => 'OPEL',
        'excel_sheet_name' => 'OPEL',
    ]);
    $gm = CarGroup::factory()->create([
        'name' => 'GM',
        'excel_sheet_name' => 'GM',
    ]);
    $child = CarGroup::factory()->create([
        'name' => 'GM CHILD',
        'excel_sheet_name' => 'GM CHILD',
        'parent_id' => $gm->id,
    ]);

    $opelExisting = Item::factory()->create([
        'car_group_id' => $opel->id,
        'serial_code' => 'GM10',
        'weight_kg' => 1.5,
        'pt_ppm' => 100,
        'pd_ppm' => 20,
        'rh_ppm' => 5,
    ]);
    $gmCollision = Item::factory()->create([
        'car_group_id' => $gm->id,
        'serial_code' => 'GM-10',
        'weight_kg' => 1.5,
        'pt_ppm' => 100,
        'pd_ppm' => 20,
        'rh_ppm' => 5,
    ]);
    $gmUnique = Item::factory()->create([
        'car_group_id' => $gm->id,
        'serial_code' => 'GM20',
        'weight_kg' => 2.0,
        'pt_ppm' => 200,
        'pd_ppm' => 30,
        'rh_ppm' => 6,
    ]);

    expect(Artisan::call('car-groups:merge-gm-into-opel'))->toBe(0)
        ->and($gmCollision->fresh()->car_group_id)->toBe($gm->id)
        ->and(CarGroup::query()->whereKey($gm->id)->exists())->toBeTrue();

    expect(Artisan::call('car-groups:merge-gm-into-opel', ['--apply' => true]))->toBe(0);

    expect(CarGroup::query()->whereKey($gm->id)->exists())->toBeFalse()
        ->and($child->fresh()->parent_id)->toBe($opel->id)
        ->and($opelExisting->fresh()->car_group_id)->toBe($opel->id)
        ->and($gmCollision->fresh()->car_group_id)->toBe($opel->id)
        ->and($gmCollision->fresh()->assay_fingerprint)->toBeNull()
        ->and($gmUnique->fresh()->car_group_id)->toBe($opel->id)
        ->and($gmUnique->fresh()->assay_fingerprint)->not->toBeNull();

    expect(Artisan::call('car-groups:merge-gm-into-opel', ['--apply' => true]))->toBe(0);
});

test('gm import alias always resolves to the existing opel category', function (): void {
    $opel = CarGroup::factory()->create([
        'name' => 'OPEL',
        'excel_sheet_name' => 'OPEL',
    ]);

    $resolved = app(ImportSheetGroupResolver::class)->resolve('GM');

    expect($resolved?->id)->toBe($opel->id)
        ->and(CarGroup::query()
            ->whereRaw('UPPER(name) = ?', ['GM'])
            ->orWhereRaw('UPPER(excel_sheet_name) = ?', ['GM'])
            ->exists())->toBeFalse();
});
