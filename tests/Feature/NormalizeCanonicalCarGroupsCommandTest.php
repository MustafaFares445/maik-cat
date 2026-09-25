<?php

use App\Models\CarGroup;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('normalizes legacy brand groups into configured canonical groups', function () {
    Config::set('imports.canonical_car_groups', ['JAPAN', 'RAZNI']);
    Config::set('imports.ecotrade_default_group', 'RAZNI');
    Config::set('imports.ecotrade_brand_groups', [
        'toyota' => 'JAPAN',
    ]);

    $japan = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'JAPAN',
        'slug' => 'japan',
        'excel_sheet_name' => 'JAPAN',
    ]);

    $razni = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'RAZNI',
        'slug' => 'razni',
        'excel_sheet_name' => 'RAZNI',
    ]);

    $toyota = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Toyota',
        'slug' => 'toyota',
        'excel_sheet_name' => 'TOYOTA',
        'source' => 'ecotrade',
    ]);

    $walker = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Walker',
        'slug' => 'walker',
        'excel_sheet_name' => 'WALKER',
        'source' => 'ecotrade',
    ]);

    $toyotaItem = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $toyota->id,
        'model' => 'Toyota',
        'serial_code' => 'TY-001',
        'weight_kg' => 0.5,
        'pt_ppm' => 100,
        'pd_ppm' => 200,
        'rh_ppm' => 10,
    ]);

    $walkerItem = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $walker->id,
        'model' => 'Walker',
        'serial_code' => 'WK-001',
        'weight_kg' => 0.6,
        'pt_ppm' => 110,
        'pd_ppm' => 210,
        'rh_ppm' => 11,
    ]);

    $this->artisan('car-groups:normalize-canonical', ['--apply' => true])
        ->expectsOutputToContain('Canonical car-group normalization completed.')
        ->assertExitCode(0);

    expect(CarGroup::query()->count())->toBe(2)
        ->and(CarGroup::query()->whereNotIn('excel_sheet_name', ['JAPAN', 'RAZNI'])->count())->toBe(0)
        ->and(Item::query()->count())->toBe(2);

    expect($toyotaItem->fresh()->car_group_id)->toBe($japan->id)
        ->and($walkerItem->fresh()->car_group_id)->toBe($razni->id);

    $this->artisan('car-groups:normalize-canonical')
        ->expectsOutputToContain('Dry run completed without database changes.')
        ->assertExitCode(0);
});
