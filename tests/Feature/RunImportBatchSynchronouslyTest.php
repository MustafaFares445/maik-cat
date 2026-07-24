<?php

use App\Jobs\ImportBatchJob;
use App\Models\ImportBatch;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

function createSynchronousImportWorkbook(string $directory, string $filename = 'catalog.xlsx'): string
{
    if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create temporary Excel directory.');
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('CatalogData');
    $sheet->fromArray([
        [
            'ConverterRefNo',
            'AdditionalDescription',
            'ManufacturerName',
            'WeightOfCarrier',
            'PtContentGT',
            'PdContentGT',
            'RhContentGT',
        ],
        ['SYNC-100', 'synchronous import', 'BMW', 1.2, 100, 200, 10],
    ], null, 'A1');

    $path = $directory.DIRECTORY_SEPARATOR.$filename;
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return $path;
}

test('imports run processes every workbook directly without dispatching a queue job', function () {
    Bus::fake();

    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maik_sync_import_'.uniqid('', true);
    $workbook = createSynchronousImportWorkbook($directory);
    Config::set('imports.excel_directory', $directory);

    try {
        $this->artisan('imports:run', [
            '--imported-by' => 'admin@example.com',
        ])
            ->expectsOutputToContain('Mode: direct import — no queue worker is required.')
            ->expectsOutputToContain('Import completed directly. No queue worker is required.')
            ->assertExitCode(0);

        Bus::assertNotDispatched(ImportBatchJob::class);

        expect(Item::query()->where('normalized_serial', 'SYNC100')->count())->toBe(1)
            ->and(ImportBatch::query()->count())->toBe(1)
            ->and(ImportBatch::query()->value('status'))->toBe('completed')
            ->and((int) ImportBatch::query()->value('rows_inserted'))->toBe(1);
    } finally {
        @unlink($workbook);
        @rmdir($directory);
    }
});
