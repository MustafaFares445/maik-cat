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

function auditPriorityAlfaRomeoRecord(): array
{
    return [
        'product_url' => 'https://www.ecotradegroup.com/en/product/alfa-romeo/50527957',
        'brand_page_url' => 'https://www.ecotradegroup.com/en/carbrand/alfa-romeo/9',
        'brand_slug' => 'alfa-romeo',
        'brand' => 'Alfa Romeo',
        'serial_code' => '50527957',
        'product_name' => '50527957',
        'thumbnail_url' => 'https://images.test/alfa-romeo-50527957.png',
        'card_price' => '',
        'card_texts' => ['Metals content', '50527957'],
        'image_urls' => ['https://images.test/alfa-romeo-50527957.png'],
        'main_image_url' => 'https://images.test/alfa-romeo-50527957.png',
        'image_count' => 1,
    ];
}

function auditPrioritySourceHash(array $record): string
{
    return sha1(
        'alfa-romeo|'
        .mb_strtoupper((string) $record['serial_code']).'|'
        .mb_strtolower((string) $record['product_url']),
    );
}

function auditPriorityJson(array $records): string
{
    $path = tempnam(sys_get_temp_dir(), 'image_link_priority_');

    if ($path === false) {
        throw new RuntimeException('Unable to create a temporary JSON file.');
    }

    file_put_contents($path, json_encode($records, JSON_THROW_ON_ERROR));

    return $path;
}

function auditPriorityPng(): string
{
    $image = imagecreatetruecolor(80, 60);
    $background = imagecolorallocate($image, 120, 90, 60);
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

function auditPriorityAttach(Item $item, array $customProperties): void
{
    $path = tempnam(sys_get_temp_dir(), 'image_link_priority_media_');

    if ($path === false) {
        throw new RuntimeException('Unable to create a temporary media file.');
    }

    $pngPath = $path.'.png';
    @unlink($path);
    file_put_contents($pngPath, auditPriorityPng());

    $item->addMedia($pngPath)
        ->withCustomProperties($customProperties)
        ->toMediaCollection('images');
}

function auditPriorityOutputPath(): string
{
    return storage_path('app/testing/image-link-priority-'.Str::uuid());
}

/** @return list<array<string,string|null>> */
function auditPriorityCsvRows(string $path): array
{
    $handle = fopen($path, 'rb');

    if ($handle === false) {
        throw new RuntimeException('Unable to open the audit CSV.');
    }

    $headers = fgetcsv($handle);
    $rows = [];

    while (($values = fgetcsv($handle)) !== false) {
        $rows[] = array_combine($headers, $values);
    }

    fclose($handle);

    return $rows;
}

function auditPriorityGroups(): array
{
    $fiat = CarGroup::factory()->create(['name' => 'FIAT', 'excel_sheet_name' => 'FIAT']);
    $alfaRomeo = CarGroup::factory()->create(['name' => 'ALFA ROMEO', 'excel_sheet_name' => 'ALFA ROMEO']);

    return [$fiat, $alfaRomeo];
}

test('it prioritizes the exact 50527957 Alfa Romeo source hash over the FIAT group mapping', function (): void {
    [, $alfaRomeo] = auditPriorityGroups();
    $record = auditPriorityAlfaRomeoRecord();
    $sourceHash = auditPrioritySourceHash($record);
    $item = Item::factory()->create([
        'car_group_id' => $alfaRomeo->id,
        'serial_code' => '50527957',
        'source_url' => $record['product_url'],
        'source_hash' => $sourceHash,
    ]);
    auditPriorityAttach($item, [
        'source' => 'ecotrade',
        'source_url' => $record['main_image_url'],
        'source_hash' => $sourceHash,
    ]);
    $jsonPath = auditPriorityJson([$record]);
    $outputPath = auditPriorityOutputPath();

    $this->artisan('media:audit-item-image-links', [
        'path' => $jsonPath,
        '--output' => $outputPath,
        '--code' => ['50527957'],
    ])
        ->expectsOutputToContain('Confirmed wrong sources: 0')
        ->assertExitCode(0);

    $auditRow = auditPriorityCsvRows($outputPath.'/image_link_audit.csv')[0];
    expect($auditRow['status'])->toBe('provenance_match')
        ->and($auditRow['reason'])->toContain('source hashes match exactly')
        ->and($auditRow['expected_serial_code'])->toBe('50527957')
        ->and($auditRow['expected_product_url'])->toBe($record['product_url'])
        ->and($auditRow['expected_source_hash'])->toBe($sourceHash)
        ->and(file_get_contents($outputPath.'/confirmed_wrong_links.csv'))->not->toContain((string) $item->id);

    @unlink($jsonPath);
});

test('it resolves the 50527957 Alfa Romeo product URL before FIAT group and serial matching', function (): void {
    [, $alfaRomeo] = auditPriorityGroups();
    $record = auditPriorityAlfaRomeoRecord();
    $sourceHash = auditPrioritySourceHash($record);
    $item = Item::factory()->create([
        'car_group_id' => $alfaRomeo->id,
        'serial_code' => '50527957',
        'source_url' => $record['product_url'],
        'source_hash' => null,
    ]);
    auditPriorityAttach($item, [
        'source' => 'ecotrade',
        'source_url' => $record['main_image_url'],
        'source_hash' => $sourceHash,
    ]);
    $jsonPath = auditPriorityJson([$record]);
    $outputPath = auditPriorityOutputPath();

    $this->artisan('media:audit-item-image-links', [
        'path' => $jsonPath,
        '--output' => $outputPath,
        '--code' => ['50527957'],
    ])->assertExitCode(0);

    $auditRow = auditPriorityCsvRows($outputPath.'/image_link_audit.csv')[0];
    expect($auditRow['status'])->toBe('provenance_match')
        ->and((int) $auditRow['reference_count'])->toBe(1)
        ->and($auditRow['expected_product_url'])->toBe($record['product_url'])
        ->and($auditRow['expected_source_hash'])->toBe($sourceHash);

    @unlink($jsonPath);
});

test('it reports the 50527957 Alfa Romeo FIAT no-reference case as a group mapping conflict', function (): void {
    [, $alfaRomeo] = auditPriorityGroups();
    $record = auditPriorityAlfaRomeoRecord();
    $sourceHash = auditPrioritySourceHash($record);
    $item = Item::factory()->create([
        'car_group_id' => $alfaRomeo->id,
        'serial_code' => '50527957',
        'source_url' => null,
        'source_hash' => null,
    ]);
    auditPriorityAttach($item, [
        'source' => 'ecotrade',
        'source_url' => $record['main_image_url'],
        'source_hash' => $sourceHash,
    ]);
    $jsonPath = auditPriorityJson([$record]);
    $outputPath = auditPriorityOutputPath();

    $this->artisan('media:audit-item-image-links', [
        'path' => $jsonPath,
        '--output' => $outputPath,
        '--code' => ['50527957'],
    ])
        ->expectsOutputToContain('Confirmed wrong sources: 0')
        ->assertExitCode(0);

    $auditRow = auditPriorityCsvRows($outputPath.'/image_link_audit.csv')[0];
    expect($auditRow['status'])->toBe('group_mapping_conflict')
        ->and((int) $auditRow['reference_count'])->toBe(0)
        ->and(file_get_contents($outputPath.'/visual_review.csv'))->toContain('group_mapping_conflict')
        ->and(file_get_contents($outputPath.'/confirmed_wrong_links.csv'))->not->toContain((string) $item->id);

    @unlink($jsonPath);
});
