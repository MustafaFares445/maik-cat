<?php

use App\Jobs\ImportBatchJob;
use App\Models\CarGroup;
use App\Models\ImportBatch;
use App\Models\Item;
use App\Services\Ecotrade\EcotradeProductImageCandidateResolver;
use App\Services\ImportBatchService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

function catalystWorkbookPath(array $dataRows): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('AUDI VW');
    $sheet->fromArray([
        ['Model', 'Serial Code', 'Piece kg', '#REF!', null, '#REF!', null, '#REF!', null, null, 'Extra Codes', null, 'Details', null, null, null, 'Shape Code'],
        [null, null, null, 'Pt', null, 'Pd', null, 'Rh'],
        [null, null, null, 'ppm', null, 'ppm', 'Price', 'ppm'],
        ...$dataRows,
    ], null, 'A1');

    $base = tempnam(sys_get_temp_dir(), 'catalyst_');
    @unlink($base);
    $path = $base.'.xlsx';
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return $path;
}

function attachTestItemImage(Item $item): void
{
    $path = tempnam(sys_get_temp_dir(), 'item_image_').'.png';
    $image = imagecreatetruecolor(48, 48);
    $background = imagecolorallocate($image, 232, 232, 232);
    $accent = imagecolorallocate($image, 92, 92, 92);

    imagefill($image, 0, 0, $background);
    imagefilledellipse($image, 24, 24, 30, 30, $accent);
    imagepng($image, $path);
    imagedestroy($image);

    $item->addMedia($path)
        ->usingFileName('source.png')
        ->toMediaCollection('images');
}

function processCapturedImportBatch(string $batchId): void
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

test('legacy import inserts distinct assays and copies the first sibling image', function () {
    Storage::fake('public');
    Bus::fake();

    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'AUDI VW',
        'excel_sheet_name' => 'AUDI VW',
        'region' => 'European',
    ]);

    $existing = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'AUDI',
        'serial_code' => 'SER-100',
        'weight_kg' => 1.1,
        'pt_ppm' => 100,
        'pd_ppm' => 200,
        'rh_ppm' => 10,
        'source' => 'excel_import',
    ]);
    attachTestItemImage($existing);

    $path = catalystWorkbookPath([
        ['AUDI', 'SER-100', 1.1, 100, null, 200, null, 10, null, null, 'ALT-1/ALT-2', null, 'existing assay', null, null, null, 'A'],
        ['AUDI', 'SER-100', 1.2, 110, null, 210, null, 11, null, null, 'ALT-3', null, 'different assay', null, null, null, 'B'],
        ['AUDI', 'SER-100', 1.2, 110, null, 210, null, 11, null, null, 'ALT-3', null, 'different assay', null, null, null, 'B'],
        [null, 'KONTROLINIS', 1, 1, null, 2, null, 0.262],
        ['AUDI', '?', 1, 1, null, 2, null, 0.2],
        ['AUDI', 'NO-WEIGHT', null, 1, null, 2, null, 0.2],
        ['AUDI', 'ZERO-METAL', 1, 0, null, 0, null, 0],
    ]);

    $report = app(ImportBatchService::class)->importFromPath($path, 'test@example.com');
    processCapturedImportBatch((string) $report['batch_id']);

    $batch = ImportBatch::query()->findOrFail($report['batch_id']);

    expect($batch->rows_inserted)->toBe(1)
        ->and($batch->rows_skipped)->toBe(2)
        ->and($batch->rows_invalid)->toBe(4)
        ->and($batch->rows_flagged)->toBe(0)
        ->and(Item::query()->where('normalized_serial', 'SER100')->count())->toBe(2);

    $newItem = Item::query()
        ->where('normalized_serial', 'SER100')
        ->where('weight_kg', 1.2)
        ->firstOrFail();

    expect($newItem->hasMedia('images'))->toBeTrue()
        ->and($newItem->extraCodes()->pluck('code')->all())->toBe(['ALT-3'])
        ->and($newItem->details)->toBe('different assay')
        ->and($newItem->shape_code)->toBe('B');

    @unlink($path);
});

test('assay fingerprint permits different analyses but rejects an exact duplicate', function () {
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'BMW',
        'excel_sheet_name' => 'BMW',
        'region' => 'European',
    ]);

    $base = [
        'car_group_id' => $group->id,
        'model' => 'BMW',
        'serial_code' => 'ABC-123',
        'source' => 'excel_import',
    ];

    Item::query()->create($base + [
        'weight_kg' => 1.0,
        'pt_ppm' => 100,
        'pd_ppm' => null,
        'rh_ppm' => null,
    ]);

    Item::query()->create($base + [
        'weight_kg' => 1.1,
        'pt_ppm' => 110,
        'pd_ppm' => null,
        'rh_ppm' => null,
    ]);

    expect(Item::query()->where('normalized_serial', 'ABC123')->count())->toBe(2);

    expect(fn () => Item::query()->create($base + [
        'weight_kg' => 1.0,
        'pt_ppm' => 100,
        'pd_ppm' => null,
        'rh_ppm' => null,
    ]))->toThrow(QueryException::class);
});

test('one metal item is priceable and API visible when it has an image', function () {
    Storage::fake('public');

    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'JAPAN',
        'excel_sheet_name' => 'JAPAN',
        'region' => 'Japanese',
    ]);

    $item = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'HONDA',
        'serial_code' => 'ONE-METAL',
        'weight_kg' => 1.4,
        'pt_ppm' => null,
        'pd_ppm' => 500,
        'rh_ppm' => null,
        'source' => 'excel_import',
    ]);
    attachTestItemImage($item);
    $item->refresh();

    expect(Item::query()->calculablePrice()->whereKey($item->id)->exists())->toBeTrue()
        ->and(Item::query()->apiVisible()->whereKey($item->id)->exists())->toBeTrue()
        ->and($item->isApiVisible())->toBeTrue();
});

test('ecotrade image resolver matches Excel items by group and normalized serial', function () {
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'AUDI VW',
        'excel_sheet_name' => 'AUDI VW',
        'region' => 'European',
    ]);

    $item = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'AUDI',
        'serial_code' => '6G-335',
        'weight_kg' => 1.2,
        'pt_ppm' => null,
        'pd_ppm' => 500,
        'rh_ppm' => 25,
        'source' => 'excel_import',
    ]);

    $records = [
        [
            'product_url' => 'https://example.com/product/6g335',
            'brand_page_url' => 'https://www.ecotradegroup.com/en/carbrand/audi',
            'brand_slug' => 'audi',
            'brand' => 'audi',
            'serial_code' => '6G 335',
            'product_name' => 'AUDI 6G335',
            'main_image_url' => 'https://cdn.example.com/6g335.png',
            'image_urls' => ['https://cdn.example.com/6g335.png'],
        ],
        [
            'product_url' => 'https://example.com/product/placeholder',
            'brand_page_url' => 'https://www.ecotradegroup.com/en/carbrand/audi',
            'brand_slug' => 'audi',
            'brand' => 'audi',
            'serial_code' => 'PLACEHOLDER',
            'product_name' => 'PLACEHOLDER',
            'main_image_url' => 'https://www.ecotradegroup.com/build/assets/website/images/mascots/mascote_en.jpg',
            'image_urls' => [],
        ],
    ];

    $resolved = app(EcotradeProductImageCandidateResolver::class)->resolve($records);

    expect($resolved['candidates'])->toHaveCount(1)
        ->and($resolved['candidates'][0]->item->is($item))->toBeTrue()
        ->and($resolved['summary']['records_rejected_placeholder_image'])->toBe(1)
        ->and($resolved['summary']['matched_items'])->toBe(1);
});
