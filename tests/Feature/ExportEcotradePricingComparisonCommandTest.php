<?php

use App\Models\CarGroup;
use App\Models\Item;
use App\Services\Mobile\MetalsSpotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

test('EcoTrade comparison export writes each target calculable item exactly once', function (): void {
    $metals = Mockery::mock(MetalsSpotService::class);
    $metals->shouldReceive('all')->once()->andReturn([
        'data' => [
            ['key' => 'platinum', 'price_gram' => 30.0],
            ['key' => 'palladium', 'price_gram' => 40.0],
            ['key' => 'rhodium', 'price_gram' => 200.0],
        ],
    ]);
    app()->instance(MetalsSpotService::class, $metals);

    $bmw = CarGroup::factory()->create(['name' => 'BMW', 'excel_sheet_name' => 'BMW']);
    $mercedes = CarGroup::factory()->create(['name' => 'MERCEDES', 'excel_sheet_name' => 'MERCEDES']);
    $psa = CarGroup::factory()->create(['name' => 'PSA', 'excel_sheet_name' => 'PSA']);
    $other = CarGroup::factory()->create(['name' => 'FORD', 'excel_sheet_name' => 'FORD']);

    foreach ([
        [$bmw, 'BMW-ONE'],
        [$mercedes, 'MB-ONE'],
        [$psa, 'PSA-ONE'],
    ] as [$group, $serial]) {
        Item::factory()->create([
            'car_group_id' => $group->id,
            'serial_code' => $serial,
            'weight_kg' => 2.0,
            'pt_ppm' => 1000,
            'pd_ppm' => 500,
            'rh_ppm' => 100,
        ]);
    }

    Item::factory()->create([
        'car_group_id' => $other->id,
        'serial_code' => 'FORD-ONE',
        'weight_kg' => 2.0,
        'pt_ppm' => 1000,
    ]);
    Item::factory()->create([
        'car_group_id' => $bmw->id,
        'serial_code' => 'BMW-NO-PRICE',
        'weight_kg' => 0,
        'pt_ppm' => 1000,
    ]);

    $path = storage_path('app/test-ecotrade-'.Str::uuid().'.xlsx');

    try {
        artisan('pricing:export-ecotrade-comparison', ['--output' => $path])
            ->assertSuccessful();

        $sheet = IOFactory::load($path)->getActiveSheet();
        expect($sheet->getHighestDataRow())->toBe(4);

        $serials = [];
        for ($row = 2; $row <= 4; $row++) {
            $serials[] = (string) $sheet->getCell("B{$row}")->getValue();
        }

        sort($serials);
        expect($serials)->toBe(['BMW-ONE', 'MB-ONE', 'PSA-ONE']);
    } finally {
        @unlink($path);
    }
});
