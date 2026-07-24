<?php

use App\Jobs\ImportBatchJob;
use App\Models\CarGroup;
use App\Models\DuplicateReview;
use App\Models\ExtraCode;
use App\Models\ImportRowIssue;
use App\Models\Item;
use App\Models\User;
use App\Services\ImportBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

use function Pest\Laravel\getJson;
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

/** @param array<string,array<int,array<int,mixed>>> $sheets */
function createExcelUpload(array $sheets, string $originalName = 'import.xlsx'): UploadedFile
{
    return createWorkbookUpload($sheets, $originalName, false);
}

/** @param array<string,array<int,array<int,mixed>>> $sheets */
function createXlsUpload(array $sheets, string $originalName = 'import.xls'): UploadedFile
{
    return createWorkbookUpload($sheets, $originalName, true);
}

/** @param array<string,array<int,array<int,mixed>>> $sheets */
function createWorkbookUpload(array $sheets, string $originalName, bool $xls): UploadedFile
{
    $spreadsheet = new Spreadsheet();
    $spreadsheet->removeSheetByIndex(0);

    foreach ($sheets as $sheetName => $rows) {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($sheetName);
        $sheet->fromArray($rows, null, 'A1');
    }

    $spreadsheet->setActiveSheetIndex(0);
    $base = tempnam(sys_get_temp_dir(), 'import_test_');

    if ($base === false) {
        throw new RuntimeException('Unable to create a workbook fixture.');
    }

    @unlink($base);
    $path = $base.($xls ? '.xls' : '.xlsx');
    $writer = $xls ? new Xls($spreadsheet) : new Xlsx($spreadsheet);
    $writer->save($path);
    $spreadsheet->disconnectWorksheets();

    return new UploadedFile(
        $path,
        $originalName,
        $xls ? 'application/vnd.ms-excel' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true,
    );
}

function queueAndRunImportJob(string $batchId): void
{
    $captured = null;

    Bus::assertDispatched(ImportBatchJob::class, function (ImportBatchJob $job) use ($batchId, &$captured): bool {
        if ($job->batchId !== $batchId) {
            return false;
        }

        $captured = $job;

        return true;
    });

    expect($captured)->toBeInstanceOf(ImportBatchJob::class);

    app(ImportBatchService::class)->processQueuedBatch(
        $captured->batchId,
        $captured->storedFilePath,
    );
}

function authenticateImportUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    return $user;
}

test('petra import detects the catalog sheet and imports a valid analysis', function () {
    authenticateImportUser();
    Bus::fake();

    $file = createExcelUpload([
        'Noise' => [['not', 'the', 'catalog']],
        'CatalogData' => [
            petraHeaders(),
            ['SER-100', 'description', 'Acadia', 1.12, 1200, 450, 90],
        ],
    ], 'petra.xlsx');

    $response = post('/api/imports', ['file' => $file]);
    $response->assertCreated()->assertJsonPath('status', 'queued');

    $batchId = (string) $response->json('batchId');
    queueAndRunImportJob($batchId);

    getJson("/api/imports/{$batchId}")
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('rowsInserted', 1)
        ->assertJsonPath('rowsInvalid', 0)
        ->assertJsonPath('rowsFlagged', 0);

    $item = Item::query()->firstOrFail();

    expect($item->serial_code)->toBe('SER-100')
        ->and($item->normalized_serial)->toBe('SER100')
        ->and($item->model)->toBe('Acadia')
        ->and($item->pt_ppm)->toBe(1200.0);

    @unlink($file->getPathname());
});

test('an exact Petra assay is skipped without a duplicate review', function () {
    authenticateImportUser();
    Bus::fake();

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
        'weight_kg' => 1.1,
        'pt_ppm' => 300,
        'pd_ppm' => 120,
        'rh_ppm' => 18,
    ]);

    $file = createExcelUpload([
        'PETRA' => [
            petraHeaders(),
            ['SER-BMW-1', 'same assay', 'BMW', 1.1, 300, 120, 18],
        ],
    ]);

    $response = post('/api/imports', ['file' => $file]);
    $batchId = (string) $response->json('batchId');
    queueAndRunImportJob($batchId);

    getJson("/api/imports/{$batchId}")
        ->assertOk()
        ->assertJsonPath('rowsInserted', 0)
        ->assertJsonPath('rowsSkipped', 1)
        ->assertJsonPath('rowsFlagged', 0)
        ->assertJsonPath('duplicatesPending', 0);

    expect(Item::query()->count())->toBe(1)
        ->and(DuplicateReview::query()->count())->toBe(0);

    @unlink($file->getPathname());
});

test('a different Petra analysis for the same serial is inserted automatically', function () {
    authenticateImportUser();
    Bus::fake();

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
        'weight_kg' => 1.0,
        'pt_ppm' => 100,
        'pd_ppm' => 200,
        'rh_ppm' => 10,
    ]);

    $file = createExcelUpload([
        'PETRA' => [
            petraHeaders(),
            ['SER-BMW-2', 'second analysis', 'BMW', 1.05, 111, 205, 11],
        ],
    ]);

    $response = post('/api/imports', ['file' => $file]);
    $batchId = (string) $response->json('batchId');
    queueAndRunImportJob($batchId);

    getJson("/api/imports/{$batchId}")
        ->assertOk()
        ->assertJsonPath('rowsInserted', 1)
        ->assertJsonPath('rowsSkipped', 0)
        ->assertJsonPath('rowsFlagged', 0)
        ->assertJsonPath('duplicatesPending', 0);

    expect(Item::query()->where('normalized_serial', 'SERBMW2')->count())->toBe(2)
        ->and(Item::query()->where('normalized_serial', 'SERBMW2')->where('pt_ppm', 111)->exists())->toBeTrue()
        ->and(DuplicateReview::query()->count())->toBe(0);

    @unlink($file->getPathname());
});

test('legacy workbook reads actual headers and imports extra fields', function () {
    authenticateImportUser();
    Bus::fake();

    CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'AUDI VW',
        'excel_sheet_name' => 'AUDI VW',
        'region' => 'European',
    ]);

    $file = createExcelUpload([
        'AUDI VW' => [
            ['Model', 'Serial Code', 'Piece kg', '#REF!', null, '#REF!', null, '#REF!', null, null, 'Extra Codes', null, 'Details', null, null, null, 'Shape Code'],
            [null, null, null, 'Pt', null, 'Pd', null, 'Rh'],
            [null, null, null, 'ppm', null, 'ppm', 'Price', 'ppm'],
            ['AUDI A4', '8K0-131-701', 1.234, 150.5, null, 220.25, null, 12.75, null, null, 'EX1/EX2', null, 'real details', null, null, null, 'SHAPE-X'],
        ],
    ], 'legacy.xlsx');

    $response = post('/api/imports', ['file' => $file]);
    $batchId = (string) $response->json('batchId');
    queueAndRunImportJob($batchId);

    $item = Item::query()->where('normalized_serial', '8K0131701')->firstOrFail();

    expect($item->model)->toBe('AUDI A4')
        ->and($item->details)->toBe('real details')
        ->and($item->shape_code)->toBe('SHAPE-X')
        ->and($item->source)->toBe('excel_import')
        ->and($item->extraCodes()->pluck('code')->all())->toBe(['EX1', 'EX2']);

    @unlink($file->getPathname());
});

test('legacy import records invalid assay rows and rejects placeholders', function () {
    authenticateImportUser();
    Bus::fake();

    CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'BMW',
        'excel_sheet_name' => 'BMW',
        'region' => 'European',
    ]);

    $file = createExcelUpload([
        'BMW' => [
            ['Model', 'Serial Code', 'Piece kg', 'Pt', null, 'Pd', null, 'Rh'],
            ['BMW', 'VALID-1', 1.2, 100, null, 200, null, 10],
            ['BMW', '?', 1.2, 100, null, 200, null, 10],
            ['BMW', 'KONTROLINIS', 1.2, 100, null, 200, null, 10],
            ['BMW', 'NO-WEIGHT', null, 100, null, 200, null, 10],
            ['BMW', 'ZERO-METAL', 1.2, 0, null, 0, null, 0],
            ['BMW', 'AMBIGUOUS', 1.2, '100/200', null, 200, null, 10],
        ],
    ], 'invalid.xlsx');

    $response = post('/api/imports', ['file' => $file]);
    $batchId = (string) $response->json('batchId');
    queueAndRunImportJob($batchId);

    getJson("/api/imports/{$batchId}")
        ->assertOk()
        ->assertJsonPath('rowsInserted', 1)
        ->assertJsonPath('rowsInvalid', 5)
        ->assertJsonPath('issuesTotal', 5);

    expect(Item::query()->count())->toBe(1)
        ->and(ImportRowIssue::query()->count())->toBe(5)
        ->and(ImportRowIssue::query()->where('issue_code', 'ambiguous_assay_value')->exists())->toBeTrue();

    @unlink($file->getPathname());
});

test('dry run profiles rows without writing items or issue records', function () {
    authenticateImportUser();

    CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'BMW',
        'excel_sheet_name' => 'BMW',
        'region' => 'European',
    ]);

    $file = createExcelUpload([
        'BMW' => [
            ['header one'],
            ['header two'],
            ['header three'],
            ['MODEL-DRY', 'SER-DRY-1', 1.1, 120, null, 200, null, 20, null, null, 'C1/C2'],
            ['MODEL-DRY', '?', 1.1, 120, null, 200, null, 20],
        ],
    ], 'dry-run.xlsx');

    $response = post('/api/imports', [
        'file' => $file,
        'dryRun' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('status', 'preview_completed')
        ->assertJsonPath('rowsInserted', 1)
        ->assertJsonPath('rowsInvalid', 1)
        ->assertJsonPath('rowsSkipped', 0)
        ->assertJsonPath('issuesTotal', 0);

    expect(Item::query()->count())->toBe(0)
        ->and(ExtraCode::query()->count())->toBe(0)
        ->and(ImportRowIssue::query()->count())->toBe(0);

    @unlink($file->getPathname());
});

test('legacy aliases and placeholder sheets are handled', function () {
    authenticateImportUser();
    Bus::fake();

    $canonical = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'CHRYSLER',
        'excel_sheet_name' => 'CHRYSLER',
        'region' => 'American',
    ]);

    $file = createExcelUpload([
        'Лист1' => [[null]],
        'Лист2' => [[null]],
        'New Chraisler' => [
            ['header one'],
            ['header two'],
            ['header three'],
            ['MODEL-CHR', 'SER-CHR-1', 1.5, 111, null, 222, null, 33],
        ],
    ], 'aliases.xlsx');

    $response = post('/api/imports', ['file' => $file]);
    $batchId = (string) $response->json('batchId');
    queueAndRunImportJob($batchId);

    $item = Item::query()->where('normalized_serial', 'SERCHR1')->firstOrFail();

    expect($item->car_group_id)->toBe($canonical->id)
        ->and(Item::query()->count())->toBe(1);

    @unlink($file->getPathname());
});

test('xls files use the same import pipeline', function () {
    authenticateImportUser();

    $file = createXlsUpload([
        'CatalogData' => [
            petraHeaders(),
            ['SER-XLS-1', 'xls row', 'Acadia', 1.12, 1200, 450, 90],
        ],
    ], 'petra.xls');

    post('/api/imports', [
        'file' => $file,
        'dryRun' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('status', 'preview_completed')
        ->assertJsonPath('rowsInserted', 1)
        ->assertJsonPath('rowsInvalid', 0);

    @unlink($file->getPathname());
});
