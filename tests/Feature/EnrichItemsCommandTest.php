<?php

use App\Models\CarGroup;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

function enrichmentPetraHeaders(): array
{
    return [
        'ConverterRefNo',
        'AdditionalDescription',
        'ManufacturerName',
        'WeightOfCarrier',
        'PtContentGT',
        'PdContentGT',
        'RhContentGT',
    ];
}

/**
 * @param  array<string, array<int, array<int, mixed>>>  $sheets
 */
function createEnrichmentWorkbookPath(array $sheets): string
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->removeSheetByIndex(0);

    $firstSheet = true;

    foreach ($sheets as $sheetName => $rows) {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($sheetName);

        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A1');
        }

        if ($firstSheet) {
            $spreadsheet->setActiveSheetIndexByName($sheetName);
            $firstSheet = false;
        }
    }

    $tempBase = tempnam(sys_get_temp_dir(), 'enrich_items_');

    if ($tempBase === false) {
        throw new RuntimeException('Failed to create temporary workbook path.');
    }

    @unlink($tempBase);

    $path = $tempBase.'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

test('legacy enrichment fills missing fields on an existing ecotrade item', function () {
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'BMW',
        'excel_sheet_name' => 'BMW',
        'region' => 'European',
        'source' => 'ecotrade',
    ]);

    $item = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'ECOTRADE MODEL',
        'serial_code' => 'SER-LEG-1',
        'weight_kg' => null,
        'pt_ppm' => null,
        'pd_ppm' => null,
        'rh_ppm' => null,
        'shape_code' => null,
        'details' => '{"source":"ecotrade"}',
        'source' => 'ecotrade',
    ]);

    $path = createEnrichmentWorkbookPath([
        'BMW' => [
            ['header row 1'],
            ['header row 2'],
            ['header row 3'],
            ['MODEL-LEG', 'SER-LEG-1', 1.234, 150.5, null, 220.25, null, 12.75, null, null, null, null, 'legacy details', null, null, null, 'SHAPE-X'],
        ],
    ]);

    try {
        $this->artisan('imports:enrich-items', [
            'paths' => [$path],
        ])
            ->expectsOutputToContain('rows_updated: 1')
            ->expectsOutputToContain('rows_created: 0')
            ->assertExitCode(0);

        $item->refresh();

        expect($item->model)->toBe('ECOTRADE MODEL')
            ->and($item->details)->toBe('{"source":"ecotrade"}')
            ->and($item->source)->toBe('ecotrade')
            ->and($item->weight_kg)->toBe(1.234)
            ->and($item->pt_ppm)->toBe(150.5)
            ->and($item->pd_ppm)->toBe(220.25)
            ->and($item->rh_ppm)->toBe(12.75)
            ->and($item->shape_code)->toBe('SHAPE-X');
    } finally {
        @unlink($path);
    }
});

test('enrichment does not overwrite existing non-null assay values', function () {
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'BMW',
        'excel_sheet_name' => 'BMW',
        'region' => 'European',
        'source' => 'ecotrade',
    ]);

    $item = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'KEPT MODEL',
        'serial_code' => 'SER-NOOP-1',
        'weight_kg' => 2.5,
        'pt_ppm' => 900.0,
        'pd_ppm' => 200.0,
        'rh_ppm' => 15.0,
        'shape_code' => 'KEEP',
        'details' => 'keep details',
        'source' => 'ecotrade',
    ]);

    $path = createEnrichmentWorkbookPath([
        'BMW' => [
            ['header row 1'],
            ['header row 2'],
            ['header row 3'],
            ['MODEL-NOOP', 'SER-NOOP-1', 9.999, 111.0, null, 333.0, null, 44.0, null, null, null, null, 'new details', null, null, null, 'NEW'],
        ],
    ]);

    try {
        $this->artisan('imports:enrich-items', [
            'paths' => [$path],
        ])
            ->expectsOutputToContain('rows_skipped_noop: 1')
            ->assertExitCode(0);

        $item->refresh();

        expect($item->weight_kg)->toBe(2.5)
            ->and($item->pt_ppm)->toBe(900.0)
            ->and($item->pd_ppm)->toBe(200.0)
            ->and($item->rh_ppm)->toBe(15.0)
            ->and($item->shape_code)->toBe('KEEP')
            ->and($item->details)->toBe('keep details');
    } finally {
        @unlink($path);
    }
});

test('legacy enrichment prefers exact pgm ppm headers and repairs implausible weights', function () {
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'VOLVO',
        'excel_sheet_name' => 'VOLVO',
        'region' => 'European',
        'source' => 'ecotrade',
    ]);

    $item = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => '8670409',
        'serial_code' => '8670409',
        'normalized_serial' => '8670409',
        'weight_kg' => 6649.0,
        'pt_ppm' => null,
        'pd_ppm' => null,
        'rh_ppm' => null,
        'shape_code' => null,
        'details' => '8670409',
        'source' => 'ecotrade',
    ]);

    $path = createEnrichmentWorkbookPath([
        'VOLVO' => [
            ['Brand', 'Serial', 'Details', 'Maker', null, 'Weight', 'Price', 'O', 'S', 'Pt total', 'Pd total', 'Rh total', 'PT', 'PD', 'RH'],
            ['VOLVO', '8670409', '2 987 945 300', 'ZEUNA STARKER', null, 1.65, null, null, null, 4537, 777, 888, 2750, 20, 30],
        ],
    ]);

    try {
        $this->artisan('imports:enrich-items', [
            'paths' => [$path],
        ])
            ->expectsOutputToContain('rows_updated: 1')
            ->assertExitCode(0);

        $item->refresh();

        expect($item->weight_kg)->toBe(1.65)
            ->and($item->pt_ppm)->toBe(2750.0)
            ->and($item->pd_ppm)->toBe(20.0)
            ->and($item->rh_ppm)->toBe(30.0);
    } finally {
        @unlink($path);
    }
});

test('petra enrichment updates exactly one matching item', function () {
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'AUDI VW',
        'excel_sheet_name' => 'AUDI VW',
        'region' => 'European',
        'source' => 'ecotrade',
    ]);

    $item = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'AUDI VW ECOTRADE',
        'serial_code' => 'SER-PET-1',
        'weight_kg' => null,
        'pt_ppm' => null,
        'pd_ppm' => null,
        'rh_ppm' => null,
        'shape_code' => null,
        'details' => '{"source":"ecotrade"}',
        'source' => 'ecotrade',
    ]);

    $path = createEnrichmentWorkbookPath([
        'Noisy' => [
            ['not', 'petra', 'headers'],
            ['garbage', 'ignored', 'sheet'],
        ],
        'CatalogData' => [
            enrichmentPetraHeaders(),
            ['SER-PET-1', 'petra details', 'Audi', 1.12, 1200, 450, 90],
        ],
    ]);

    try {
        $this->artisan('imports:enrich-items', [
            'paths' => [$path],
        ])
            ->assertExitCode(0);

        $item->refresh();

        expect($item->weight_kg)->toBe(1.12)
            ->and($item->pt_ppm)->toBe(1200.0)
            ->and($item->pd_ppm)->toBe(450.0)
            ->and($item->rh_ppm)->toBe(90.0)
            ->and($item->details)->toBe('{"source":"ecotrade"}');
    } finally {
        @unlink($path);
    }
});

test('enrichment skips ambiguous matches within the same group and serial', function () {
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'BMW',
        'excel_sheet_name' => 'BMW',
        'region' => 'European',
        'source' => 'ecotrade',
    ]);

    Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'AMB-1',
        'serial_code' => 'SER-AMB-1',
        'weight_kg' => null,
        'pt_ppm' => null,
        'pd_ppm' => null,
        'rh_ppm' => null,
        'shape_code' => null,
        'details' => 'one',
        'source' => 'ecotrade',
    ]);

    Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'AMB-2',
        'serial_code' => 'SER-AMB-1',
        'weight_kg' => null,
        'pt_ppm' => null,
        'pd_ppm' => null,
        'rh_ppm' => null,
        'shape_code' => null,
        'details' => 'two',
        'source' => 'ecotrade',
    ]);

    $path = createEnrichmentWorkbookPath([
        'BMW' => [
            ['header row 1'],
            ['header row 2'],
            ['header row 3'],
            ['MODEL-AMB', 'SER-AMB-1', 1.5, 100.0, null, 50.0, null, 10.0, null, null, null, null, 'ambiguous', null, null, null, 'AMB'],
        ],
    ]);

    try {
        $this->artisan('imports:enrich-items', [
            'paths' => [$path],
        ])
            ->expectsOutputToContain('rows_skipped_ambiguous: 1')
            ->assertExitCode(0);

        $items = Item::query()
            ->where('car_group_id', $group->id)
            ->where('serial_code', 'SER-AMB-1')
            ->orderBy('model')
            ->get();

        expect($items)->toHaveCount(2)
            ->and($items[0]->weight_kg)->toBeNull()
            ->and($items[0]->shape_code)->toBeNull()
            ->and($items[1]->weight_kg)->toBeNull()
            ->and($items[1]->shape_code)->toBeNull();
    } finally {
        @unlink($path);
    }
});

test('enrichment creates a missing item and group with excel_import source', function () {
    $path = createEnrichmentWorkbookPath([
        'My New Brand' => [
            ['header row 1'],
            ['header row 2'],
            ['header row 3'],
            ['MODEL-NEW', 'SER-NEW-1', 1.5, 123.4, null, 56.7, null, 8.9, null, null, null, null, 'new item details', null, null, null, 'SH-NEW'],
        ],
    ]);

    try {
        $this->artisan('imports:enrich-items', [
            'paths' => [$path],
        ])
            ->expectsOutputToContain('rows_created: 1')
            ->expectsOutputToContain('groups_created: 1')
            ->assertExitCode(0);

        $group = CarGroup::query()->where('excel_sheet_name', 'MY NEW BRAND')->first();
        expect($group)->not->toBeNull()
            ->and($group->source)->toBe('excel_import');

        $item = Item::query()->where('serial_code', 'SER-NEW-1')->first();
        expect($item)->not->toBeNull()
            ->and($item->car_group_id)->toBe($group->id)
            ->and($item->model)->toBe('MODEL-NEW')
            ->and($item->details)->toBe('new item details')
            ->and($item->weight_kg)->toBe(1.5)
            ->and($item->pt_ppm)->toBe(123.4)
            ->and($item->pd_ppm)->toBe(56.7)
            ->and($item->rh_ppm)->toBe(8.9)
            ->and($item->shape_code)->toBe('SH-NEW')
            ->and($item->source)->toBe('excel_import');
    } finally {
        @unlink($path);
    }
});

test('dry run reports counts correctly and leaves the database unchanged', function () {
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'BMW',
        'excel_sheet_name' => 'BMW',
        'region' => 'European',
        'source' => 'ecotrade',
    ]);

    $item = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'DRY MODEL',
        'serial_code' => 'SER-DRY-1',
        'weight_kg' => null,
        'pt_ppm' => null,
        'pd_ppm' => null,
        'rh_ppm' => null,
        'shape_code' => null,
        'details' => '{"source":"ecotrade"}',
        'source' => 'ecotrade',
    ]);

    $path = createEnrichmentWorkbookPath([
        'BMW' => [
            ['header row 1'],
            ['header row 2'],
            ['header row 3'],
            ['MODEL-DRY', 'SER-DRY-1', 1.1, 100.0, null, 20.0, null, 3.0, null, null, null, null, 'dry update', null, null, null, 'DRY'],
        ],
        'Dry New Brand' => [
            ['header row 1'],
            ['header row 2'],
            ['header row 3'],
            ['MODEL-NEW-DRY', 'SER-DRY-NEW', 2.2, 200.0, null, 40.0, null, 5.0, null, null, null, null, 'dry new', null, null, null, 'NEW'],
        ],
    ]);

    try {
        $this->artisan('imports:enrich-items', [
            'paths' => [$path],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Totals:')
            ->expectsOutputToContain('rows_scanned: 2')
            ->expectsOutputToContain('rows_updated: 1')
            ->expectsOutputToContain('rows_created: 1')
            ->expectsOutputToContain('groups_created: 1')
            ->expectsOutputToContain('Dry run completed without persisting changes.')
            ->assertExitCode(0);

        $item->refresh();

        expect($item->weight_kg)->toBeNull()
            ->and($item->pt_ppm)->toBeNull()
            ->and($item->pd_ppm)->toBeNull()
            ->and($item->rh_ppm)->toBeNull()
            ->and($item->shape_code)->toBeNull()
            ->and(CarGroup::query()->where('excel_sheet_name', 'DRY NEW BRAND')->doesntExist())->toBeTrue()
            ->and(Item::query()->where('serial_code', 'SER-DRY-NEW')->doesntExist())->toBeTrue();
    } finally {
        @unlink($path);
    }
});

test('enrichment processes multiple files independently without leaking duplicate state', function () {
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'BMW',
        'excel_sheet_name' => 'BMW',
        'region' => 'European',
        'source' => 'ecotrade',
    ]);

    $item = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'MULTI MODEL',
        'serial_code' => 'SER-MULTI-1',
        'weight_kg' => null,
        'pt_ppm' => null,
        'pd_ppm' => null,
        'rh_ppm' => null,
        'shape_code' => null,
        'details' => '{"source":"ecotrade"}',
        'source' => 'ecotrade',
    ]);

    $fileOne = createEnrichmentWorkbookPath([
        'BMW' => [
            ['header row 1'],
            ['header row 2'],
            ['header row 3'],
            ['MODEL-MULTI', 'SER-MULTI-1', 1.25, 150.0, null, 75.0, null, 10.0, null, null, null, null, 'multi file details', null, null, null, 'MULTI'],
        ],
    ]);

    $fileTwo = createEnrichmentWorkbookPath([
        'BMW' => [
            ['header row 1'],
            ['header row 2'],
            ['header row 3'],
            ['MODEL-MULTI', 'SER-MULTI-1', 1.25, 150.0, null, 75.0, null, 10.0, null, null, null, null, 'multi file details', null, null, null, 'MULTI'],
        ],
    ]);

    try {
        $this->artisan('imports:enrich-items', [
            'paths' => [$fileOne, $fileTwo],
        ])
            ->expectsOutputToContain('rows_updated: 1')
            ->expectsOutputToContain('rows_skipped_noop: 1')
            ->expectsOutputToContain('rows_skipped_duplicate_in_file: 0')
            ->assertExitCode(0);

        $item->refresh();

        expect($item->weight_kg)->toBe(1.25)
            ->and($item->pt_ppm)->toBe(150.0)
            ->and($item->pd_ppm)->toBe(75.0)
            ->and($item->rh_ppm)->toBe(10.0)
            ->and($item->shape_code)->toBe('MULTI');
    } finally {
        @unlink($fileOne);
        @unlink($fileTwo);
    }
});
