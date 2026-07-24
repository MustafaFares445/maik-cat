<?php

use App\Services\ImportBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function createImportDirectoryFixture(): array
{
    $directory = sys_get_temp_dir().'/excel_import_'.uniqid('', true);
    $nested = $directory.'/nested';

    mkdir($nested, 0775, true);
    file_put_contents($directory.'/first.xlsx', 'xlsx');
    file_put_contents($nested.'/second.xls', 'xls');
    file_put_contents($directory.'/ignored.csv', 'csv');
    file_put_contents($directory.'/~$temporary.xlsx', 'temporary');

    return [
        'directory' => $directory,
        'first' => realpath($directory.'/first.xlsx'),
        'second' => realpath($nested.'/second.xls'),
    ];
}

function removeImportDirectoryFixture(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $path) {
        $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
    }

    rmdir($directory);
}

test('imports run scans every Excel workbook from the configured folder without a path', function () {
    $fixture = createImportDirectoryFixture();
    config()->set('imports.excel_directory', $fixture['directory']);

    $this->mock(ImportBatchService::class, function (MockInterface $mock) use ($fixture): void {
        $mock->shouldReceive('importFromPath')
            ->once()
            ->with($fixture['first'], 'admin@example.com', true)
            ->andReturn([
                'batch_id' => 'batch-first',
                'status' => 'preview_completed',
                'rows_inserted' => 10,
                'rows_skipped' => 2,
                'rows_flagged' => 1,
                'rows_invalid' => 3,
                'duplicates_total' => 0,
            ]);

        $mock->shouldReceive('importFromPath')
            ->once()
            ->with($fixture['second'], 'admin@example.com', true)
            ->andReturn([
                'batch_id' => 'batch-second',
                'status' => 'preview_completed',
                'rows_inserted' => 5,
                'rows_skipped' => 4,
                'rows_flagged' => 0,
                'rows_invalid' => 1,
                'duplicates_total' => 0,
            ]);
    });

    try {
        $this->artisan('imports:run', [
            '--dry-run' => true,
            '--imported-by' => 'admin@example.com',
        ])
            ->expectsOutputToContain('Excel files found: 2')
            ->expectsOutputToContain('Combined result')
            ->assertExitCode(0);
    } finally {
        removeImportDirectoryFixture($fixture['directory']);
    }
});

test('imports run reports a missing supplied directory', function () {
    $missing = sys_get_temp_dir().'/missing_excel_'.uniqid('', true);

    $this->artisan('imports:run', [
        'path' => $missing,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Import discovery failed:')
        ->assertExitCode(1);
});
