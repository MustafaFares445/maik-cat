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

function groupMappingRecord(string $serial = '50527957'): array
{
    $productUrl = 'https://www.ecotradegroup.com/en/product/alfa-romeo-chrysler-fiat-lancia/'.Str::slug($serial);
    $imageUrl = 'https://images.test/'.Str::slug($serial).'.png';

    return [
        'product_url' => $productUrl,
        'brand_page_url' => 'https://www.ecotradegroup.com/en/carbrand/alfa-romeo/9',
        'brand_slug' => 'alfa-romeo',
        'brand' => 'Alfa Romeo',
        'serial_code' => $serial,
        'product_name' => $serial,
        'thumbnail_url' => $imageUrl,
        'card_price' => '',
        'card_texts' => ['Metals content', $serial],
        'image_urls' => [$imageUrl],
        'main_image_url' => $imageUrl,
        'image_count' => 1,
    ];
}

function groupMappingSourceHash(array $record): string
{
    return sha1(
        'alfa-romeo|'
        .mb_strtoupper((string) $record['serial_code']).'|'
        .mb_strtolower((string) $record['product_url']),
    );
}

function groupMappingJson(array $records): string
{
    $path = tempnam(sys_get_temp_dir(), 'group_mapping_json_');

    if ($path === false) {
        throw new RuntimeException('Unable to create JSON fixture.');
    }

    file_put_contents($path, json_encode($records, JSON_THROW_ON_ERROR));

    return $path;
}

function groupMappingPng(): string
{
    $image = imagecreatetruecolor(80, 60);
    $background = imagecolorallocate($image, 120, 90, 60);
    imagefill($image, 0, 0, $background);
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    imagedestroy($image);

    if (! is_string($bytes)) {
        throw new RuntimeException('Unable to create PNG fixture.');
    }

    return $bytes;
}

function groupMappingAttach(Item $item, array $properties): mixed
{
    $path = tempnam(sys_get_temp_dir(), 'group_mapping_media_');

    if ($path === false) {
        throw new RuntimeException('Unable to create media fixture.');
    }

    $png = $path.'.png';
    @unlink($path);
    file_put_contents($png, groupMappingPng());

    return $item
        ->addMedia($png)
        ->usingFileName('50527957-maikcat.png')
        ->withCustomProperties($properties)
        ->toMediaCollection('images');
}

function groupMappingReport(array $row): string
{
    $headers = [
        'status', 'reason', 'item_id', 'car_group_id', 'car_group', 'serial_code', 'normalized_serial',
        'extra_codes', 'primary_family_key', 'matched_family_keys', 'reference_count', 'item_source_url',
        'item_source_hash', 'media_id', 'media_file_name', 'media_sha256', 'media_source', 'media_source_url',
        'media_source_hash', 'current_image_url', 'expected_serial_code', 'expected_product_url',
        'expected_image_url', 'expected_source_hash', 'visual_score', 'visual_error',
    ];
    $path = tempnam(sys_get_temp_dir(), 'group_mapping_report_');

    if ($path === false) {
        throw new RuntimeException('Unable to create audit CSV fixture.');
    }

    $handle = fopen($path, 'wb');
    fputcsv($handle, $headers);
    fputcsv($handle, array_map(static fn (string $header): string => (string) ($row[$header] ?? ''), $headers));
    fclose($handle);

    return $path;
}

function groupMappingOutput(): string
{
    return storage_path('app/testing/group-mapping-remediation-'.Str::uuid());
}

test('it plans and applies a safe group mapping correction without changing image media', function (): void {
    $fiat = CarGroup::factory()->create(['name' => 'FIAT', 'excel_sheet_name' => 'FIAT']);
    $alfa = CarGroup::factory()->create(['name' => 'ALFA ROMEO', 'excel_sheet_name' => 'ALFA ROMEO']);
    $record = groupMappingRecord();
    $sourceHash = groupMappingSourceHash($record);
    $item = Item::factory()->create([
        'car_group_id' => $alfa->id,
        'serial_code' => '50527957',
        'source' => null,
        'source_url' => null,
        'source_hash' => null,
    ]);
    $media = groupMappingAttach($item, [
        'source' => 'ecotrade',
        'source_url' => $record['main_image_url'],
        'source_hash' => $sourceHash,
        'gemini_result' => 'edited',
    ]);
    $mediaKey = $media->getKey();
    $mediaBytesHash = hash_file('sha256', $media->getPath());
    $json = groupMappingJson([$record]);
    $report = groupMappingReport([
        'status' => 'group_mapping_conflict',
        'item_id' => $item->id,
        'car_group_id' => $alfa->id,
        'car_group' => 'ALFA ROMEO',
        'serial_code' => '50527957',
        'normalized_serial' => '50527957',
        'media_id' => $media->id,
        'media_file_name' => $media->file_name,
        'media_source_url' => $record['main_image_url'],
        'media_source_hash' => $sourceHash,
    ]);
    $dryOutput = groupMappingOutput();

    $this->artisan('media:remediate-item-group-mapping', [
        'report' => $report,
        'json' => $json,
        '--output' => $dryOutput,
    ])
        ->expectsOutputToContain('Safe identity corrections: 1')
        ->expectsOutputToContain('Corrections applied: 0')
        ->assertExitCode(0);

    expect($item->fresh()->car_group_id)->toBe($alfa->id)
        ->and($item->fresh()->source_hash)->toBeNull();
    $plan = file_get_contents($dryOutput.'/identity_remediation_plan.csv');
    expect($plan)
        ->toContain((string) $fiat->id)
        ->toContain($record['product_url'])
        ->toContain($sourceHash)
        ->toContain('media_source_hash');

    $applyOutput = groupMappingOutput();
    $this->artisan('media:remediate-item-group-mapping', [
        'report' => $report,
        'json' => $json,
        '--output' => $applyOutput,
        '--apply' => true,
    ])
        ->expectsOutputToContain('Safe identity corrections: 1')
        ->expectsOutputToContain('Corrections applied: 1')
        ->assertExitCode(0);

    $fresh = $item->fresh('media');
    expect($fresh->car_group_id)->toBe($fiat->id)
        ->and($fresh->source)->toBe('ecotrade')
        ->and($fresh->source_url)->toBe($record['product_url'])
        ->and($fresh->source_hash)->toBe($sourceHash)
        ->and($fresh->getFirstMedia('images')->getKey())->toBe($mediaKey)
        ->and(hash_file('sha256', $fresh->getFirstMedia('images')->getPath()))->toBe($mediaBytesHash);

    @unlink($json);
    @unlink($report);
});

test('it refuses to change the group when media product serial does not match the item serial', function (): void {
    $fiat = CarGroup::factory()->create(['name' => 'FIAT', 'excel_sheet_name' => 'FIAT']);
    $alfa = CarGroup::factory()->create(['name' => 'ALFA ROMEO', 'excel_sheet_name' => 'ALFA ROMEO']);
    $record = groupMappingRecord('50527957');
    $sourceHash = groupMappingSourceHash($record);
    $item = Item::factory()->create([
        'car_group_id' => $alfa->id,
        'serial_code' => 'OTHER-123',
        'source_url' => null,
        'source_hash' => null,
    ]);
    $media = groupMappingAttach($item, [
        'source' => 'ecotrade',
        'source_url' => $record['main_image_url'],
        'source_hash' => $sourceHash,
    ]);
    $json = groupMappingJson([$record]);
    $report = groupMappingReport([
        'status' => 'group_mapping_conflict',
        'item_id' => $item->id,
        'car_group_id' => $alfa->id,
        'car_group' => 'ALFA ROMEO',
        'serial_code' => 'OTHER-123',
        'normalized_serial' => 'OTHER123',
        'media_id' => $media->id,
        'media_source_url' => $record['main_image_url'],
        'media_source_hash' => $sourceHash,
    ]);
    $output = groupMappingOutput();

    $this->artisan('media:remediate-item-group-mapping', [
        'report' => $report,
        'json' => $json,
        '--output' => $output,
        '--apply' => true,
    ])
        ->expectsOutputToContain('Safe identity corrections: 0')
        ->expectsOutputToContain('Still unresolved: 1')
        ->assertExitCode(0);

    expect($item->fresh()->car_group_id)->toBe($alfa->id)
        ->and($item->fresh()->source_hash)->toBeNull();
    expect(file_get_contents($output.'/identity_remediation_unsolved.csv'))
        ->toContain('media_product_serial_does_not_match_item_serial');

    @unlink($json);
    @unlink($report);
});

test('it refuses to overwrite conflicting existing item provenance', function (): void {
    $fiat = CarGroup::factory()->create(['name' => 'FIAT', 'excel_sheet_name' => 'FIAT']);
    $alfa = CarGroup::factory()->create(['name' => 'ALFA ROMEO', 'excel_sheet_name' => 'ALFA ROMEO']);
    $record = groupMappingRecord();
    $sourceHash = groupMappingSourceHash($record);
    $item = Item::factory()->create([
        'car_group_id' => $alfa->id,
        'serial_code' => '50527957',
        'source_url' => 'https://example.test/different-product',
        'source_hash' => str_repeat('a', 40),
    ]);
    $media = groupMappingAttach($item, [
        'source' => 'ecotrade',
        'source_url' => $record['main_image_url'],
        'source_hash' => $sourceHash,
    ]);
    $json = groupMappingJson([$record]);
    $report = groupMappingReport([
        'status' => 'group_mapping_conflict',
        'item_id' => $item->id,
        'car_group_id' => $alfa->id,
        'car_group' => 'ALFA ROMEO',
        'serial_code' => '50527957',
        'normalized_serial' => '50527957',
        'media_id' => $media->id,
        'media_source_url' => $record['main_image_url'],
        'media_source_hash' => $sourceHash,
    ]);
    $output = groupMappingOutput();

    $this->artisan('media:remediate-item-group-mapping', [
        'report' => $report,
        'json' => $json,
        '--output' => $output,
        '--apply' => true,
    ])
        ->expectsOutputToContain('Safe identity corrections: 0')
        ->assertExitCode(0);

    $fresh = $item->fresh();
    expect($fresh->car_group_id)->toBe($alfa->id)
        ->and($fresh->source_hash)->toBe(str_repeat('a', 40))
        ->and($fresh->source_url)->toBe('https://example.test/different-product');
    expect(file_get_contents($output.'/identity_remediation_unsolved.csv'))
        ->toContain('existing_item_source_hash_conflicts_with_media_product');

    @unlink($json);
    @unlink($report);
});
