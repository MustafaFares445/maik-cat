<?php

use App\Models\CarGroup;
use App\Models\DuplicateReview;
use App\Models\ExtraCode;
use App\Models\ImportBatch;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

function petraHeaders(): array
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
function createExcelUpload(array $sheets, string $originalName = 'import.xlsx'): UploadedFile
{
    $spreadsheet = new Spreadsheet();
    $spreadsheet->removeSheetByIndex(0);

    $firstSheet = true;
    foreach ($sheets as $sheetName => $rows) {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($sheetName);

        if (! empty($rows)) {
            $sheet->fromArray($rows, null, 'A1');
        }

        if ($firstSheet) {
            $spreadsheet->setActiveSheetIndexByName($sheetName);
            $firstSheet = false;
        }
    }

    $tempBase = tempnam(sys_get_temp_dir(), 'xlsx_');
    if ($tempBase === false) {
        throw new RuntimeException('Could not create temporary file for test workbook.');
    }

    @unlink($tempBase);
    $path = $tempBase . '.xlsx';

    $writer = new Xlsx($spreadsheet);
    $writer->save($path);

    return new UploadedFile(
        $path,
        $originalName,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true
    );
}

test('petra import auto-detects header-matching sheet and ignores noisy sheet', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $file = createExcelUpload([
        'Noisy' => [
            ['not', 'petra', 'headers'],
            ['garbage', 'data', 'ignored'],
        ],
        'CatalogData' => [
            petraHeaders(),
            ['SER-100', 'desc one', 'Acadia', 1.12, 1200, 450, 90],
        ],
    ], 'petra.xlsx');

    $response = post('/api/imports', ['file' => $file]);

    $response->assertCreated();
    $response->assertJsonPath('status', 'completed');
    $response->assertJsonPath('rowsInserted', 1);
    $response->assertJsonPath('rowsInvalid', 0);
    $response->assertJsonPath('rowsFlagged', 0);
    $response->assertJsonPath('rowsSkipped', 0);

    expect(Item::query()->count())->toBe(1);
    $item = Item::query()->firstOrFail();
    expect($item->serial_code)->toBe('SER-100')
        ->and($item->model)->toBe('Acadia')
        ->and($item->details)->toBe('desc one')
        ->and($item->pt_ppm)->toBe(1200.0)
        ->and($item->pd_ppm)->toBe(450.0)
        ->and($item->rh_ppm)->toBe(90.0);

    @unlink($file->getPathname());
});

test('petra import creates missing manufacturer group automatically', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $file = createExcelUpload([
        'Sheet2' => [
            petraHeaders(),
            ['SER-101', 'new brand row', 'My New Brand', 0.95, 500, 100, 50],
        ],
    ], 'petra-groups.xlsx');

    $response = post('/api/imports', ['file' => $file]);
    $response->assertCreated();

    $group = CarGroup::query()->where('excel_sheet_name', 'MY NEW BRAND')->first();
    expect($group)->not->toBeNull()
        ->and($group->name)->toBe('MY NEW BRAND');

    @unlink($file->getPathname());
});

test('petra import skips exact duplicate and does not create duplicate review', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'BMW',
        'excel_sheet_name' => 'BMW',
        'region' => 'European',
    ]);

    Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'BMW',
        'serial_code' => 'SER-BMW-1',
        'weight_kg' => 1.100,
        'pt_ppm' => 300.0000,
        'pd_ppm' => 120.0000,
        'rh_ppm' => 18.0000,
        'details' => 'old',
        'shape_code' => null,
    ]);

    $file = createExcelUpload([
        'PETRA' => [
            petraHeaders(),
            ['SER-BMW-1', 'new details ignored', 'BMW', 1.1, 300, 120, 18],
        ],
    ]);

    $response = post('/api/imports', ['file' => $file]);

    $response->assertCreated();
    $response->assertJsonPath('rowsInserted', 0);
    $response->assertJsonPath('rowsSkipped', 1);
    $response->assertJsonPath('rowsFlagged', 0);
    $response->assertJsonPath('rowsInvalid', 0);

    expect(DuplicateReview::query()->count())->toBe(0);
    expect(Item::query()->count())->toBe(1);

    @unlink($file->getPathname());
});

test('petra import flags conflict duplicates and exposes batch duplicate endpoints', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'BMW',
        'excel_sheet_name' => 'BMW',
        'region' => 'European',
    ]);

    Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'BMW',
        'serial_code' => 'SER-BMW-2',
        'weight_kg' => 1.000,
        'pt_ppm' => 100.0000,
        'pd_ppm' => 200.0000,
        'rh_ppm' => 10.0000,
        'details' => 'existing',
        'shape_code' => null,
    ]);

    $file = createExcelUpload([
        'PETRA' => [
            petraHeaders(),
            ['SER-BMW-2', 'new conflict', 'BMW', 1.0, 111, 200, 10],
        ],
    ]);

    $importResponse = post('/api/imports', ['file' => $file]);
    $importResponse->assertCreated();
    $importResponse->assertJsonPath('rowsInserted', 0);
    $importResponse->assertJsonPath('rowsFlagged', 1);
    $importResponse->assertJsonPath('rowsSkipped', 0);

    $batchId = (string) $importResponse->json('batchId');
    expect($batchId)->not->toBe('');

    getJson("/api/imports/{$batchId}")
        ->assertOk()
        ->assertJsonPath('id', $batchId)
        ->assertJsonPath('rowsFlagged', 1)
        ->assertJsonPath('duplicatesPending', 1)
        ->assertJsonPath('status', 'completed');

    getJson("/api/imports/{$batchId}/duplicates")
        ->assertOk()
        ->assertJsonPath('data.0.batchId', $batchId)
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.payload.serialCode', 'SER-BMW-2')
        ->assertJsonPath('data.0.payload.ptPpm', 111);

    expect(DuplicateReview::query()->count())->toBe(1);

    @unlink($file->getPathname());
});

test('duplicate resolve endpoint handles keep overwrite and insert actions', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'BMW',
        'excel_sheet_name' => 'BMW',
        'region' => 'European',
    ]);

    $existing = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'BMW',
        'serial_code' => 'SER-BMW-3',
        'weight_kg' => 1.000,
        'pt_ppm' => 100.0000,
        'pd_ppm' => 200.0000,
        'rh_ppm' => 10.0000,
        'details' => 'existing',
        'shape_code' => null,
    ]);

    ExtraCode::query()->create([
        'id' => (string) Str::uuid(),
        'item_id' => $existing->id,
        'code' => 'OLD-CODE',
        'source' => 'seed',
    ]);

    $batch = ImportBatch::query()->create([
        'id' => (string) Str::uuid(),
        'file_name' => 'petra.xlsx',
        'imported_by' => $user->email,
        'status' => 'completed',
        'rows_inserted' => 0,
        'rows_skipped' => 0,
        'rows_flagged' => 3,
        'rows_invalid' => 0,
    ]);

    $basePayload = [
        'model' => 'BMW NEW',
        'serial_code' => 'SER-BMW-3',
        'weight_kg' => 1.200,
        'pt_ppm' => 150.0000,
        'pd_ppm' => 250.0000,
        'rh_ppm' => 12.0000,
        'details' => 'updated details',
        'shape_code' => null,
    ];

    $keepReview = DuplicateReview::query()->create([
        'id' => (string) Str::uuid(),
        'batch_id' => $batch->id,
        'excel_row' => 12,
        'excel_sheet' => 'PETRA',
        'payload' => $basePayload,
        'existing_item_id' => $existing->id,
        'status' => 'pending',
    ]);

    $overwriteReview = DuplicateReview::query()->create([
        'id' => (string) Str::uuid(),
        'batch_id' => $batch->id,
        'excel_row' => 13,
        'excel_sheet' => 'PETRA',
        'payload' => array_merge($basePayload, [
            'extra_codes' => 'NEW1/NEW2',
        ]),
        'existing_item_id' => $existing->id,
        'status' => 'pending',
    ]);

    $insertReview = DuplicateReview::query()->create([
        'id' => (string) Str::uuid(),
        'batch_id' => $batch->id,
        'excel_row' => 14,
        'excel_sheet' => 'PETRA',
        'payload' => array_merge($basePayload, [
            'serial_code' => 'SER-BMW-3-NEW',
            'extra_codes' => 'IN1/IN2',
        ]),
        'existing_item_id' => $existing->id,
        'status' => 'pending',
    ]);

    patchJson("/api/duplicates/{$keepReview->id}", ['action' => 'keep'])
        ->assertOk()
        ->assertJsonPath('status', 'kept');

    patchJson("/api/duplicates/{$overwriteReview->id}", ['action' => 'overwrite'])
        ->assertOk()
        ->assertJsonPath('status', 'overwritten');

    patchJson("/api/duplicates/{$insertReview->id}", ['action' => 'insert'])
        ->assertOk()
        ->assertJsonPath('status', 'inserted');

    $existing->refresh();

    expect($existing->model)->toBe('BMW NEW')
        ->and($existing->weight_kg)->toBe(1.2)
        ->and($existing->pt_ppm)->toBe(150.0)
        ->and($existing->details)->toBe('updated details');

    expect($existing->extraCodes()->pluck('code')->all())->toBe(['NEW1', 'NEW2']);

    expect(Item::query()->where('serial_code', 'SER-BMW-3-NEW')->exists())->toBeTrue();
});

test('legacy multi-sheet import flow still works with new orchestration', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'BMW',
        'excel_sheet_name' => 'BMW',
        'region' => 'European',
    ]);

    $legacyDataRow = [
        'MODEL-LEG',
        'SER-LEG-1',
        1.234,
        150.5,
        null,
        220.25,
        null,
        12.75,
        null,
        null,
        'EX1/EX2',
        null,
        'legacy details',
        null,
        null,
        null,
        'SHAPE-X',
    ];

    $file = createExcelUpload([
        'BMW' => [
            ['header row 1'],
            ['header row 2'],
            ['header row 3'],
            $legacyDataRow,
        ],
    ], 'legacy.xlsx');

    $response = post('/api/imports', ['file' => $file]);
    $response->assertCreated();
    $response->assertJsonPath('status', 'completed');
    $response->assertJsonPath('rowsInserted', 1);
    $response->assertJsonPath('rowsFlagged', 0);
    $response->assertJsonPath('rowsInvalid', 0);

    $item = Item::query()->where('serial_code', 'SER-LEG-1')->first();
    expect($item)->not->toBeNull()
        ->and($item->model)->toBe('MODEL-LEG')
        ->and($item->details)->toBe('legacy details')
        ->and($item->shape_code)->toBe('SHAPE-X');

    expect(ExtraCode::query()->where('item_id', $item->id)->pluck('code')->all())->toBe(['EX1', 'EX2']);

    @unlink($file->getPathname());
});
