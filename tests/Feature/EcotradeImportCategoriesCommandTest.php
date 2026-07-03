<?php

use App\Models\CarGroup;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

function ecotradeCategoriesTempFile(string $contents): string
{
    $base = tempnam(sys_get_temp_dir(), 'ecotrade_categories_');

    if ($base === false) {
        throw new RuntimeException('Failed to allocate a temporary Ecotrade categories file.');
    }

    @unlink($base);

    $path = $base.'.json';
    file_put_contents($path, $contents);

    return $path;
}

function ecotradeCategoriesRecord(): array
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

/**
 * @param  array<int, string>  $sheetNames
 */
function ecotradeCategoriesWorkbookFile(array $sheetNames): string
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->removeSheetByIndex(0);

    foreach ($sheetNames as $index => $sheetName) {
        $sheet = $spreadsheet->createSheet($index);
        $sheet->setTitle($sheetName);
    }

    $base = tempnam(sys_get_temp_dir(), 'ecotrade_categories_workbook_');

    if ($base === false) {
        throw new RuntimeException('Failed to allocate a temporary Ecotrade workbook file.');
    }

    @unlink($base);

    $path = $base.'.xlsx';
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return $path;
}

test('imports Ecotrade categories and relinks matching items', function () {
    $record = ecotradeCategoriesRecord();
    $jsonPath = ecotradeCategoriesTempFile(json_encode([$record], JSON_THROW_ON_ERROR));

    $previousGroup = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Legacy Group',
        'excel_sheet_name' => 'LEGACY GROUP',
        'region' => null,
        'source' => null,
        'slug' => 'legacy-group',
    ]);

    $item = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $previousGroup->id,
        'model' => 'Legacy Model',
        'serial_code' => 'LEGACY SERIAL',
        'normalized_serial' => Item::normalizeSerialValue('LEGACY SERIAL'),
        'weight_kg' => null,
        'pt_ppm' => null,
        'pd_ppm' => null,
        'rh_ppm' => null,
        'shape_code' => null,
        'details' => 'old details',
        'source' => 'ecotrade',
        'source_url' => $record['product_url'],
        'source_hash' => sha1('acura|'.mb_strtoupper('ACURA MDX 04 FRONT').'|'.mb_strtolower($record['product_url'])),
    ]);

    try {
        $this->artisan('ecotrade:import-categories', [
            'path' => $jsonPath,
        ])
            ->expectsOutputToContain('groups_created: 1')
            ->expectsOutputToContain('items_linked: 1')
            ->assertExitCode(0);

        $brand = CarGroup::query()
            ->where('source', 'ecotrade')
            ->where('slug', 'acura')
            ->firstOrFail();

        $item->refresh();

        expect($brand->name)->toBe('Acura')
            ->and($item->car_group_id)->toBe($brand->id);
    } finally {
        @unlink($jsonPath);
    }
});

test('dry run does not persist category or item changes', function () {
    $record = ecotradeCategoriesRecord();
    $jsonPath = ecotradeCategoriesTempFile(json_encode([$record], JSON_THROW_ON_ERROR));

    $previousGroup = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Legacy Group',
        'excel_sheet_name' => 'LEGACY GROUP',
        'region' => null,
        'source' => null,
        'slug' => 'legacy-group',
    ]);

    $item = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $previousGroup->id,
        'model' => 'Legacy Model',
        'serial_code' => 'LEGACY SERIAL',
        'normalized_serial' => Item::normalizeSerialValue('LEGACY SERIAL'),
        'weight_kg' => null,
        'pt_ppm' => null,
        'pd_ppm' => null,
        'rh_ppm' => null,
        'shape_code' => null,
        'details' => 'old details',
        'source' => 'ecotrade',
        'source_url' => $record['product_url'],
        'source_hash' => sha1('acura|'.mb_strtoupper('ACURA MDX 04 FRONT').'|'.mb_strtolower($record['product_url'])),
    ]);

    try {
        $this->artisan('ecotrade:import-categories', [
            'path' => $jsonPath,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Dry run completed without persisting changes.')
            ->assertExitCode(0);

        expect(CarGroup::query()->where('source', 'ecotrade')->count())->toBe(0);

        $item->refresh();

        expect($item->car_group_id)->toBe($previousGroup->id);
    } finally {
        @unlink($jsonPath);
    }
});

test('fresh resets existing Ecotrade categories before rebuilding', function () {
    $record = ecotradeCategoriesRecord();
    $jsonPath = ecotradeCategoriesTempFile(json_encode([$record], JSON_THROW_ON_ERROR));

    $staleGroup = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Old Acura',
        'excel_sheet_name' => 'OLD ACURA',
        'region' => null,
        'source' => 'ecotrade',
        'slug' => 'acura-old',
        'source_url' => $record['brand_page_url'],
    ]);

    $item = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $staleGroup->id,
        'model' => 'Old Model',
        'serial_code' => 'OLD SERIAL',
        'normalized_serial' => Item::normalizeSerialValue('OLD SERIAL'),
        'weight_kg' => null,
        'pt_ppm' => null,
        'pd_ppm' => null,
        'rh_ppm' => null,
        'shape_code' => null,
        'details' => 'old details',
        'source' => 'ecotrade',
        'source_url' => $record['product_url'],
        'source_hash' => sha1('acura|'.mb_strtoupper('ACURA MDX 04 FRONT').'|'.mb_strtolower($record['product_url'])),
    ]);

    try {
        $this->artisan('ecotrade:import-categories', [
            'path' => $jsonPath,
            '--fresh' => true,
        ])
            ->expectsOutputToContain('groups_reset: 1')
            ->expectsOutputToContain('items_linked: 1')
            ->assertExitCode(0);

        expect(CarGroup::query()->where('slug', 'acura-old')->doesntExist())->toBeTrue()
            ->and(CarGroup::query()->where('slug', 'ecotrade-unlinked')->exists())->toBeTrue();

        $brand = CarGroup::query()
            ->where('source', 'ecotrade')
            ->where('slug', 'acura')
            ->firstOrFail();

        $item->refresh();

        expect($item->car_group_id)->toBe($brand->id);
    } finally {
        @unlink($jsonPath);
    }
});

test('unlink missing moves unmatched Ecotrade items to the fallback group', function () {
    $record = ecotradeCategoriesRecord();
    $jsonPath = ecotradeCategoriesTempFile(json_encode([$record], JSON_THROW_ON_ERROR));

    $legacyGroup = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Legacy Group',
        'excel_sheet_name' => 'LEGACY GROUP',
        'region' => null,
        'source' => null,
        'slug' => 'legacy-group',
    ]);

    $matchedItem = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $legacyGroup->id,
        'model' => 'Matched Model',
        'serial_code' => 'MATCHED SERIAL',
        'normalized_serial' => Item::normalizeSerialValue('MATCHED SERIAL'),
        'weight_kg' => null,
        'pt_ppm' => null,
        'pd_ppm' => null,
        'rh_ppm' => null,
        'shape_code' => null,
        'details' => 'matched details',
        'source' => 'ecotrade',
        'source_url' => $record['product_url'],
        'source_hash' => sha1('acura|'.mb_strtoupper('ACURA MDX 04 FRONT').'|'.mb_strtolower($record['product_url'])),
    ]);

    $missingItem = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $legacyGroup->id,
        'model' => 'Missing Model',
        'serial_code' => 'MISSING SERIAL',
        'normalized_serial' => Item::normalizeSerialValue('MISSING SERIAL'),
        'weight_kg' => null,
        'pt_ppm' => null,
        'pd_ppm' => null,
        'rh_ppm' => null,
        'shape_code' => null,
        'details' => 'missing details',
        'source' => 'ecotrade',
        'source_url' => 'https://www.ecotradegroup.com/en/product/acura/missing-item',
        'source_hash' => sha1('acura|'.mb_strtoupper('MISSING SERIAL').'|https://www.ecotradegroup.com/en/product/acura/missing-item'),
    ]);

    try {
        $this->artisan('ecotrade:import-categories', [
            'path' => $jsonPath,
            '--unlink-missing' => true,
        ])
            ->expectsOutputToContain('items_unlinked: 1')
            ->expectsOutputToContain('items_linked: 1')
            ->assertExitCode(0);

        $fallback = CarGroup::query()
            ->where('source', 'ecotrade')
            ->where('slug', 'ecotrade-unlinked')
            ->firstOrFail();

        $brand = CarGroup::query()
            ->where('source', 'ecotrade')
            ->where('slug', 'acura')
            ->firstOrFail();

        $matchedItem->refresh();
        $missingItem->refresh();

        expect($matchedItem->car_group_id)->toBe($brand->id)
            ->and($missingItem->car_group_id)->toBe($fallback->id);
    } finally {
        @unlink($jsonPath);
    }
});

test('imports Ecotrade categories from an Excel workbook and links items by brand', function () {
    $path = ecotradeCategoriesWorkbookFile([
        'AUDI VW',
        'BMW',
    ]);

    $legacyGroup = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Legacy Group',
        'excel_sheet_name' => 'LEGACY GROUP',
        'region' => null,
        'source' => null,
        'slug' => 'legacy-group',
    ]);

    $audiItem = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $legacyGroup->id,
        'model' => 'Audi model',
        'serial_code' => 'AUDI-1',
        'normalized_serial' => Item::normalizeSerialValue('AUDI-1'),
        'weight_kg' => null,
        'pt_ppm' => null,
        'pd_ppm' => null,
        'rh_ppm' => null,
        'shape_code' => null,
        'details' => 'audi details',
        'source' => 'ecotrade',
        'source_url' => 'https://www.ecotradegroup.com/en/product/audi/audi-1',
        'source_hash' => sha1('audi|'.mb_strtoupper('AUDI-1').'|https://www.ecotradegroup.com/en/product/audi/audi-1'),
    ]);

    $bmwItem = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $legacyGroup->id,
        'model' => 'BMW model',
        'serial_code' => 'BMW-1',
        'normalized_serial' => Item::normalizeSerialValue('BMW-1'),
        'weight_kg' => null,
        'pt_ppm' => null,
        'pd_ppm' => null,
        'rh_ppm' => null,
        'shape_code' => null,
        'details' => 'bmw details',
        'source' => 'ecotrade',
        'source_url' => 'https://www.ecotradegroup.com/en/product/bmw/bmw-1',
        'source_hash' => sha1('bmw|'.mb_strtoupper('BMW-1').'|https://www.ecotradegroup.com/en/product/bmw/bmw-1'),
    ]);

    $missingItem = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $legacyGroup->id,
        'model' => 'Toyota model',
        'serial_code' => 'TOYOTA-1',
        'normalized_serial' => Item::normalizeSerialValue('TOYOTA-1'),
        'weight_kg' => null,
        'pt_ppm' => null,
        'pd_ppm' => null,
        'rh_ppm' => null,
        'shape_code' => null,
        'details' => 'toyota details',
        'source' => 'ecotrade',
        'source_url' => 'https://www.ecotradegroup.com/en/product/toyota/toyota-1',
        'source_hash' => sha1('toyota|'.mb_strtoupper('TOYOTA-1').'|https://www.ecotradegroup.com/en/product/toyota/toyota-1'),
    ]);

    try {
        $this->artisan('ecotrade:import-categories', [
            'path' => $path,
            '--unlink-missing' => true,
        ])
            ->expectsOutputToContain('groups_created: 2')
            ->expectsOutputToContain('items_linked: 2')
            ->expectsOutputToContain('items_unlinked: 1')
            ->assertExitCode(0);

        $audiGroup = CarGroup::query()
            ->where('source', 'ecotrade')
            ->where('excel_sheet_name', 'AUDI VW')
            ->firstOrFail();

        $bmwGroup = CarGroup::query()
            ->where('source', 'ecotrade')
            ->where('excel_sheet_name', 'BMW')
            ->firstOrFail();

        $fallback = CarGroup::query()
            ->where('source', 'ecotrade')
            ->where('slug', 'ecotrade-unlinked')
            ->firstOrFail();

        $audiItem->refresh();
        $bmwItem->refresh();
        $missingItem->refresh();

        expect($audiItem->car_group_id)->toBe($audiGroup->id)
            ->and($bmwItem->car_group_id)->toBe($bmwGroup->id)
            ->and($missingItem->car_group_id)->toBe($fallback->id);
    } finally {
        @unlink($path);
    }
});
