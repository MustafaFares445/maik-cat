<?php

use App\Models\ImportBatch;
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
