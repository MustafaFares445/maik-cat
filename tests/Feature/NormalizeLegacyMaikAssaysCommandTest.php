<?php

use App\Models\CarGroup;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

test('normalizes only items exactly verified against the legacy Maik workbook', function (): void {
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'SWEDEN',
        'excel_sheet_name' => 'SWEDEN',
    ]);

    $matched = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'VOLVO',
        'serial_code' => '30616690',
        'weight_kg' => 0.52,
        'pt_ppm' => 2.157,
        'pd_ppm' => 1.022,
        'rh_ppm' => null,
        'source' => 'ecotrade',
    ]);

    $unmatched = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'VOLVO',
        'serial_code' => 'NOT-IN-SOURCE',
        'weight_kg' => 0.52,
        'pt_ppm' => 2.157,
        'pd_ppm' => 1.022,
        'rh_ppm' => null,
        'source' => 'excel_import',
    ]);

    $path = legacyMaikWorkbookPath();

    try {
        $this->artisan('items:normalize-legacy-maik-assays', ['--path' => $path])
            ->expectsOutputToContain('matched_items: 1')
            ->expectsOutputToContain('updated_items: 1')
            ->assertExitCode(0);

        expect($matched->refresh()->pt_ppm)->toBe(2157.0)
            ->and($matched->pd_ppm)->toBe(1022.0)
            ->and($matched->source)->toBe('legacy_maik_g_per_kg')
            ->and($unmatched->refresh()->pt_ppm)->toBe(2.157)
            ->and($unmatched->source)->toBe('excel_import');

        $this->artisan('items:normalize-legacy-maik-assays', [
            '--path' => $path,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('verified_existing_items: 1')
            ->expectsOutputToContain('unverified_existing_items: 0')
            ->assertExitCode(0);
    } finally {
        @unlink($path);
    }
});

function legacyMaikWorkbookPath(): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('SWEDEN');
    $sheet->fromArray([
        ['Model', 'Serial Code', 'Piece kg', 'Pt', null, 'Pd', null, 'Rh'],
        [null, null, null, null, null, null, null, null],
        [null, null, null, null, null, null, null, null],
        ['VOLVO', '30616690', 0.52, 2.157, null, 1.022],
    ], null, 'A1');

    $tempBase = tempnam(sys_get_temp_dir(), 'legacy_maik_');
    if ($tempBase === false) {
        throw new RuntimeException('Failed to create temporary legacy Maik workbook.');
    }

    @unlink($tempBase);
    $path = $tempBase.'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}
