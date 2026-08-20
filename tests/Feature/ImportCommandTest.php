<?php

use App\Models\ImportBatch;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

test('imports run command supports dry run for local files', function () {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('BMW');
    $sheet->fromArray([
        ['header row 1'],
        ['header row 2'],
        ['header row 3'],
        ['MODEL-CLI', 'SER-CLI-1', 1.11, 100, null, 200, null, 10],
    ], null, 'A1');

    $tempBase = tempnam(sys_get_temp_dir(), 'import_cli_');
    if ($tempBase === false) {
        throw new RuntimeException('Failed to create temporary path for import command test.');
    }

    @unlink($tempBase);
    $path = $tempBase.'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    $this->artisan('imports:run', [
        'path' => $path,
        '--dry-run' => true,
        '--imported-by' => 'cli@test.local',
    ])->assertExitCode(0);

    $batch = ImportBatch::query()->firstOrFail();
    expect($batch->status)->toBe('preview_completed')
        ->and($batch->imported_by)->toBe('cli@test.local')
        ->and($batch->rows_inserted)->toBe(1);

    @unlink($path);
});

test('imports legacy Maik gram per kilogram assays as ppm', function () {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('SWEDEN');
    $sheet->fromArray([
        ['Model', 'Serial Code', 'Piece kg', '=SUM(kitko!F150/31.1043)', null, '=SUM(kitko!F151/31.1043)', null, '=SUM(kitko!F152/31.1043)', null, null, null, 'Extra Codes', null, 'Details'],
        [null, null, null, 'Pt', null, 'Pd', null, 'Rh'],
        [null, null, null, 'ppm', 'Price', 'ppm', 'Price', 'ppm', 'Price', 'Price US$ Piece'],
        ['VOLVO', '30616690', 0.52, 2.157, null, 1.022, null, null, null, null, null, '6649 / 2227898200'],
        ['SUBARU', 'FCAG6', 4305, 0.458, null, 3.395, null, 0.425],
    ], null, 'A1');

    $tempBase = tempnam(sys_get_temp_dir(), 'import_maik_');
    if ($tempBase === false) {
        throw new RuntimeException('Failed to create temporary path for import command test.');
    }

    @unlink($tempBase);
    $path = $tempBase.'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    try {
        $this->artisan('imports:run', ['path' => $path])->assertExitCode(0);

        $item = Item::query()->where('normalized_serial', '30616690')->firstOrFail();
        $legacyGramWeightItem = Item::query()->where('normalized_serial', 'FCAG6')->firstOrFail();

        expect($item->weight_kg)->toBe(0.52)
            ->and($item->pt_ppm)->toBe(2157.0)
            ->and($item->pd_ppm)->toBe(1022.0)
            ->and($item->rh_ppm)->toBeNull()
            ->and($legacyGramWeightItem->weight_kg)->toBe(4.305)
            ->and($legacyGramWeightItem->pt_ppm)->toBe(458.0)
            ->and($legacyGramWeightItem->pd_ppm)->toBe(3395.0)
            ->and($legacyGramWeightItem->rh_ppm)->toBe(425.0);
    } finally {
        @unlink($path);
    }
});
