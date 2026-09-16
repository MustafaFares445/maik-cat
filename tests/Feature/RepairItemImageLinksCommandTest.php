<?php

use App\Models\CarGroup;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

function existingImageRepairPng(int $red = 100, int $green = 80, int $blue = 60): string
{
    $image = imagecreatetruecolor(80, 60);
    $background = imagecolorallocate($image, $red, $green, $blue);
    imagefill($image, 0, 0, $background);
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    imagedestroy($image);

    if (! is_string($bytes)) {
        throw new RuntimeException('Unable to create PNG test bytes.');
    }

    return $bytes;
}

function existingImageRepairAttach(Item $item, array $properties, string $fileName = 'existing-maikcat.png'): mixed
{
    $path = tempnam(sys_get_temp_dir(), 'existing_image_repair_');

    if ($path === false) {
        throw new RuntimeException('Unable to create a temporary image file.');
    }

    $pngPath = $path.'.png';
    @unlink($path);
    file_put_contents($pngPath, existingImageRepairPng());

    return $item
        ->addMedia($pngPath)
        ->usingFileName($fileName)
        ->withCustomProperties($properties)
        ->toMediaCollection('images');
}

function existingImageRepairReport(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'existing_image_repair_report_');

    if ($path === false) {
        throw new RuntimeException('Unable to create repair report.');
    }

    $headers = [
        'status', 'reason', 'item_id', 'car_group_id', 'car_group', 'serial_code', 'normalized_serial',
        'extra_codes', 'primary_family_key', 'matched_family_keys', 'reference_count', 'item_source_url',
        'item_source_hash', 'media_id', 'media_file_name', 'media_sha256', 'media_source', 'media_source_url',
        'media_source_hash', 'current_image_url', 'expected_serial_code', 'expected_product_url',
        'expected_image_url', 'expected_source_hash', 'visual_score', 'visual_error',
    ];
    $handle = fopen($path, 'wb');

    if ($handle === false) {
        throw new RuntimeException('Unable to open repair report.');
    }

    fputcsv($handle, $headers);

    foreach ($rows as $row) {
        fputcsv($handle, array_map(
            static fn (string $header): string => (string) ($row[$header] ?? ''),
            $headers,
        ));
    }

    fclose($handle);

    return $path;
}

function existingImageRepairOutput(): string
{
    return storage_path('app/testing/existing-image-repair-'.Str::uuid());
}

test('it reuses a trusted existing project image to repair a missing media file without downloading Ecotrade', function (): void {
    $group = CarGroup::factory()->create(['name' => 'FIAT', 'excel_sheet_name' => 'FIAT']);
    $sourceHash = sha1('fiat|46758796|https://www.ecotradegroup.com/en/product/alfa-romeo-fiat-lancia/46758796');
    $productUrl = 'https://www.ecotradegroup.com/en/product/alfa-romeo-fiat-lancia/46758796';

    $donor = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => '46758796',
        'source_url' => $productUrl,
        'source_hash' => $sourceHash,
    ]);
    $donorMedia = existingImageRepairAttach($donor, [
        'source' => 'ecotrade',
        'source_url' => 'https://images.test/46758796.png',
        'source_hash' => $sourceHash,
        'gemini_result' => 'edited',
        'maikcat_watermark' => true,
    ], '46758796-maikcat.png');

    $target = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => '46758796',
        'source_url' => $productUrl,
        'source_hash' => $sourceHash,
    ]);
    $missingMedia = existingImageRepairAttach($target, [
        'source' => 'ecotrade',
        'source_url' => 'https://images.test/46758796.png',
        'source_hash' => $sourceHash,
    ], 'missing-46758796-maikcat.png');
    @unlink($missingMedia->getPath());

    $report = existingImageRepairReport([
        [
            'status' => 'provenance_match',
            'item_id' => $donor->id,
            'car_group_id' => $group->id,
            'car_group' => 'FIAT',
            'serial_code' => '46758796',
            'normalized_serial' => '46758796',
            'reference_count' => '1',
            'item_source_url' => $productUrl,
            'item_source_hash' => $sourceHash,
            'media_id' => $donorMedia->id,
            'media_file_name' => $donorMedia->file_name,
            'media_source_hash' => $sourceHash,
            'expected_product_url' => $productUrl,
            'expected_source_hash' => $sourceHash,
        ],
        [
            'status' => 'missing_media_file',
            'item_id' => $target->id,
            'car_group_id' => $group->id,
            'car_group' => 'FIAT',
            'serial_code' => '46758796',
            'normalized_serial' => '46758796',
            'reference_count' => '1',
            'item_source_url' => $productUrl,
            'item_source_hash' => $sourceHash,
            'media_id' => $missingMedia->id,
            'media_file_name' => $missingMedia->file_name,
            'media_source_hash' => $sourceHash,
            'expected_product_url' => $productUrl,
            'expected_source_hash' => $sourceHash,
        ],
    ]);
    $output = existingImageRepairOutput();

    $this->artisan('media:repair-item-image-links', [
        'report' => $report,
        '--output' => $output,
        '--apply' => true,
    ])
        ->expectsOutputToContain('Repairable from existing images: 1')
        ->expectsOutputToContain('Images repaired: 1')
        ->expectsOutputToContain('Unsolved images: 0')
        ->assertExitCode(0);

    $newMedia = $target->fresh('media')->getFirstMedia('images');
    expect($newMedia)->not->toBeNull()
        ->and($newMedia->getKey())->not->toBe($missingMedia->getKey())
        ->and(is_file($newMedia->getPath()))->toBeTrue()
        ->and($newMedia->getCustomProperty('source_hash'))->toBe($sourceHash)
        ->and($newMedia->getCustomProperty('repair_method'))->toBe('existing_project_media')
        ->and($newMedia->getCustomProperty('repair_source_media_id'))->toBe($donorMedia->id);
    expect(file_get_contents($output.'/repair_results.csv'))->toContain((string) $target->id);

    @unlink($report);
});

test('it treats the old 50527957 Alfa Romeo exact source hash false positive as already correct', function (): void {
    $group = CarGroup::factory()->create(['name' => 'Alfa Romeo', 'excel_sheet_name' => 'ALFA ROMEO']);
    $sourceHash = '2c1cb397269cad5c4008557518f990cc49851867';
    $productUrl = 'https://www.ecotradegroup.com/en/product/alfa-romeo-chrysler-fiat-lancia/50527957';
    $item = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => '50527957',
        'source_url' => $productUrl,
        'source_hash' => $sourceHash,
    ]);
    $media = existingImageRepairAttach($item, [
        'source' => 'ecotrade',
        'source_url' => 'https://www.ecotradegroup.com/cache/product_thumb/uploads/products/18861/path-08b-43213.png',
        'source_hash' => $sourceHash,
        'gemini_result' => 'edited',
    ], '50527957-maikcat.png');
    $report = existingImageRepairReport([[
        'status' => 'confirmed_wrong_source',
        'item_id' => $item->id,
        'car_group_id' => $group->id,
        'car_group' => 'Alfa Romeo',
        'serial_code' => '50527957',
        'normalized_serial' => '50527957',
        'reference_count' => '0',
        'item_source_url' => $productUrl,
        'item_source_hash' => $sourceHash,
        'media_id' => $media->id,
        'media_file_name' => $media->file_name,
        'media_source_hash' => $sourceHash,
    ]]);
    $output = existingImageRepairOutput();

    $this->artisan('media:repair-item-image-links', [
        'report' => $report,
        '--output' => $output,
    ])
        ->expectsOutputToContain('Already correct: 1')
        ->expectsOutputToContain('Repairable from existing images: 0')
        ->expectsOutputToContain('Unsolved images: 0')
        ->assertExitCode(0);

    expect($item->fresh('media')->getFirstMedia('images')->getKey())->toBe($media->getKey());

    @unlink($report);
});

test('it repairs a provenance visual mismatch from a trusted same-product donor', function (): void {
    $group = CarGroup::factory()->create(['name' => 'OPEL', 'excel_sheet_name' => 'OPEL']);
    $sourceHash = sha1('opel|GM 18|https://www.ecotradegroup.com/en/product/opel-vauxhall/gm-18');
    $productUrl = 'https://www.ecotradegroup.com/en/product/opel-vauxhall/gm-18';
    $donor = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'GM 18',
        'source_url' => $productUrl,
        'source_hash' => $sourceHash,
    ]);
    $donorMedia = existingImageRepairAttach($donor, [
        'source' => 'ecotrade',
        'source_hash' => $sourceHash,
    ], 'gm-18-correct-maikcat.png');
    $target = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'GM 18',
        'source_url' => $productUrl,
        'source_hash' => $sourceHash,
    ]);
    $targetMedia = existingImageRepairAttach($target, [
        'source' => 'ecotrade',
        'source_hash' => $sourceHash,
    ], 'gm-18-wrong-maikcat.png');
    $report = existingImageRepairReport([
        [
            'status' => 'provenance_match',
            'item_id' => $donor->id,
            'serial_code' => 'GM 18',
            'normalized_serial' => 'GM18',
            'reference_count' => '1',
            'item_source_url' => $productUrl,
            'item_source_hash' => $sourceHash,
            'media_id' => $donorMedia->id,
            'media_source_hash' => $sourceHash,
            'expected_product_url' => $productUrl,
            'expected_source_hash' => $sourceHash,
        ],
        [
            'status' => 'provenance_match_visual_review',
            'item_id' => $target->id,
            'serial_code' => 'GM 18',
            'normalized_serial' => 'GM18',
            'reference_count' => '1',
            'item_source_url' => $productUrl,
            'item_source_hash' => $sourceHash,
            'media_id' => $targetMedia->id,
            'media_source_hash' => $sourceHash,
            'expected_product_url' => $productUrl,
            'expected_source_hash' => $sourceHash,
        ],
    ]);
    $output = existingImageRepairOutput();

    $this->artisan('media:repair-item-image-links', [
        'report' => $report,
        '--output' => $output,
        '--apply' => true,
    ])
        ->expectsOutputToContain('Already correct: 1')
        ->expectsOutputToContain('Images repaired: 1')
        ->assertExitCode(0);

    $newMedia = $target->refresh()->getFirstMedia('images');
    expect($newMedia->getKey())->not->toBe($targetMedia->getKey())
        ->and($newMedia->getCustomProperty('repair_source_media_id'))->toBe($donorMedia->id);

    @unlink($report);
});

test('it never uses raw Ecotrade direct imports as repair donors', function (): void {
    $group = CarGroup::factory()->create(['name' => 'FIAT', 'excel_sheet_name' => 'FIAT']);
    $sourceHash = sha1('fiat|TEST100|https://www.ecotradegroup.com/en/product/fiat/test100');
    $productUrl = 'https://www.ecotradegroup.com/en/product/fiat/test100';
    $donor = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'TEST100',
        'source_url' => $productUrl,
        'source_hash' => $sourceHash,
    ]);
    $rawMedia = existingImageRepairAttach($donor, [
        'source' => 'ecotrade',
        'source_url' => 'https://images.test/raw-test100.png',
        'source_hash' => $sourceHash,
        'import_method' => 'direct',
        'gemini_result' => null,
    ], 'test100-ecotrade-direct.png');
    $target = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'TEST100',
        'source_url' => $productUrl,
        'source_hash' => $sourceHash,
    ]);
    $targetMedia = existingImageRepairAttach($target, [
        'source' => 'ecotrade',
        'source_hash' => $sourceHash,
    ], 'missing-test100.png');
    @unlink($targetMedia->getPath());
    $report = existingImageRepairReport([
        [
            'status' => 'provenance_match',
            'item_id' => $donor->id,
            'car_group_id' => $group->id,
            'serial_code' => 'TEST100',
            'normalized_serial' => 'TEST100',
            'reference_count' => '1',
            'item_source_url' => $productUrl,
            'item_source_hash' => $sourceHash,
            'media_id' => $rawMedia->id,
            'media_file_name' => $rawMedia->file_name,
            'media_source_hash' => $sourceHash,
            'expected_product_url' => $productUrl,
            'expected_source_hash' => $sourceHash,
        ],
        [
            'status' => 'missing_media_file',
            'item_id' => $target->id,
            'car_group_id' => $group->id,
            'serial_code' => 'TEST100',
            'normalized_serial' => 'TEST100',
            'reference_count' => '1',
            'item_source_url' => $productUrl,
            'item_source_hash' => $sourceHash,
            'media_id' => $targetMedia->id,
            'media_file_name' => $targetMedia->file_name,
            'media_source_hash' => $sourceHash,
            'expected_product_url' => $productUrl,
            'expected_source_hash' => $sourceHash,
        ],
    ]);
    $output = existingImageRepairOutput();

    $this->artisan('media:repair-item-image-links', [
        'report' => $report,
        '--output' => $output,
    ])
        ->expectsOutputToContain('Trusted donor media: 0')
        ->expectsOutputToContain('Repairable from existing images: 0')
        ->expectsOutputToContain('Unsolved images: 1')
        ->assertExitCode(0);

    expect(file_get_contents($output.'/unsolved_images.csv'))
        ->toContain((string) $target->id)
        ->toContain('no_trusted_existing_project_image');

    @unlink($report);
});
