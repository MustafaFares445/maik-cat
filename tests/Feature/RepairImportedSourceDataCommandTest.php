<?php

use App\Models\CarGroup;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

function repairTempFile(string $contents, string $suffix): string
{
    $base = tempnam(sys_get_temp_dir(), 'repair_import_');

    if ($base === false) {
        throw new RuntimeException('Failed to allocate a temporary repair file.');
    }

    @unlink($base);

    $path = $base.$suffix;
    file_put_contents($path, $contents);

    return $path;
}

/**
 * @param  array<string, array<int, array<int, mixed>>>  $sheets
 */
function repairWorkbookPath(array $sheets): string
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

    $tempBase = tempnam(sys_get_temp_dir(), 'repair_workbook_');

    if ($tempBase === false) {
        throw new RuntimeException('Failed to create temporary workbook path.');
    }

    @unlink($tempBase);

    $path = $tempBase.'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function repairEcotradeRecord(): array
{
    return [
        'product_url' => 'https://www.ecotradegroup.com/en/product/acura/acura-mdx-04-front',
        'brand_page_url' => 'https://www.ecotradegroup.com/en/carbrand/acura',
        'brand_slug' => 'acura',
        'brand' => 'acura',
        'serial_code' => 'ACURA MDX 04 FRONT',
        'product_name' => 'ACURA MDX 04 FRONT',
        'thumbnail_url' => 'https://www.ecotradegroup.com/cache/product_thumb/uploads/products/32248/path-10d-.png',
        'card_price' => '',
        'card_texts' => ['Metals content', 'ACURA MDX 04 FRONT'],
        'image_urls' => ['https://www.ecotradegroup.com/cache/product_thumb/uploads/products/32248/path-10d-.png'],
        'main_image_url' => 'https://www.ecotradegroup.com/cache/product_thumb/uploads/products/32248/path-10d-.png',
        'image_count' => 1,
    ];
}

test('repairs Ecotrade serial and source hash from the json source file', function () {
    $record = repairEcotradeRecord();
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Acura',
        'excel_sheet_name' => 'ACURA',
        'region' => null,
        'source' => 'ecotrade',
        'slug' => 'acura',
        'source_url' => $record['brand_page_url'],
    ]);

    $wrongSerial = 'WRONG SERIAL';
    $item = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'WRONG MODEL',
        'serial_code' => $wrongSerial,
        'normalized_serial' => Item::normalizeSerialValue($wrongSerial),
        'weight_kg' => null,
        'pt_ppm' => null,
        'pd_ppm' => null,
        'rh_ppm' => null,
        'shape_code' => null,
        'details' => 'old details',
        'source' => 'ecotrade',
        'source_url' => $record['product_url'],
        'source_hash' => sha1('acura|'.mb_strtoupper($wrongSerial).'|'.mb_strtolower($record['product_url'])),
    ]);

    $jsonPath = repairTempFile(json_encode([$record], JSON_THROW_ON_ERROR), '.json');

    try {
        $this->artisan('imports:repair-source-data', [
            'paths' => [$jsonPath],
        ])
            ->expectsOutputToContain('rows_updated: 1')
            ->assertExitCode(0);

        $item->refresh();
        $details = json_decode((string) $item->details, true, 512, JSON_THROW_ON_ERROR);

        expect($item->model)->toBe('ACURA MDX 04 FRONT')
            ->and($item->serial_code)->toBe('ACURA MDX 04 FRONT')
            ->and($item->normalized_serial)->toBe(Item::normalizeSerialValue('ACURA MDX 04 FRONT'))
            ->and($item->source_url)->toBe($record['product_url'])
            ->and($item->source_hash)->toBe(sha1('acura|'.mb_strtoupper('ACURA MDX 04 FRONT').'|'.mb_strtolower($record['product_url'])))
            ->and($details['serial_code'])->toBe('ACURA MDX 04 FRONT')
            ->and($details['brand_slug'])->toBe('acura');
    } finally {
        @unlink($jsonPath);
    }
});

test('repairs serial codes from an Excel workbook row', function () {
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Toyota',
        'excel_sheet_name' => 'TOYOTA',
        'region' => 'Asian',
        'source' => 'ecotrade',
    ]);

    $wrongSerial = 'VVTI ..';
    $item = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'Toyota',
        'serial_code' => $wrongSerial,
        'normalized_serial' => Item::normalizeSerialValue($wrongSerial),
        'weight_kg' => 0.95,
        'pt_ppm' => 2383.5,
        'pd_ppm' => 26.25,
        'rh_ppm' => 687.75,
        'shape_code' => null,
        'details' => '2731 | TOYOTA | VVTI | SIYANA NO NUMBER | manifold 2pcs',
        'source' => 'excel_import',
    ]);

    $path = repairWorkbookPath([
        'Toyota' => [
            ['ConverterRefNo', 'AdditionalDescription', 'ManufacturerName', 'WeightOfCarrier', 'PtContentGT', 'PdContentGT', 'RhContentGT'],
            ['VVTI', '2731 | TOYOTA | VVTI | SIYANA NO NUMBER | manifold 2pcs', 'Toyota', 0.95, 2383.5, 26.25, 687.75],
        ],
    ]);

    try {
        $this->artisan('imports:repair-source-data', [
            'paths' => [$path],
        ])
            ->expectsOutputToContain('rows_updated: 1')
            ->assertExitCode(0);

        $item->refresh();

        expect($item->serial_code)->toBe('VVTI')
            ->and($item->normalized_serial)->toBe(Item::normalizeSerialValue('VVTI'))
            ->and($item->model)->toBe('Toyota')
            ->and($item->weight_kg)->toBe(0.95)
            ->and($item->pt_ppm)->toBe(2383.5)
            ->and($item->pd_ppm)->toBe(26.25)
            ->and($item->rh_ppm)->toBe(687.75);
    } finally {
        @unlink($path);
    }
});
