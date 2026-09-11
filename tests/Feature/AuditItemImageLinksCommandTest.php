<?php

use App\Models\CarGroup;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

function imageLinkAuditRecord(string $serial, array $overrides = []): array
{
    $slug = Str::slug($serial);

    return array_merge([
        'product_url' => "https://www.ecotradegroup.com/en/product/opel-vauxhall/{$slug}",
        'brand_page_url' => 'https://www.ecotradegroup.com/en/carbrand/opel/25',
        'brand_slug' => '25',
        'brand' => '25',
        'serial_code' => $serial,
        'product_name' => $serial,
        'thumbnail_url' => "https://images.test/{$slug}.png",
        'card_price' => '',
        'card_texts' => ['Metals content', $serial],
        'image_urls' => ["https://images.test/{$slug}.png"],
        'main_image_url' => "https://images.test/{$slug}.png",
        'image_count' => 1,
    ], $overrides);
}

function imageLinkAuditSourceHash(array $record): string
{
    return sha1('opel|'.mb_strtoupper($record['serial_code']).'|'.mb_strtolower($record['product_url']));
}

function imageLinkAuditJson(array $records): string
{
    $path = tempnam(sys_get_temp_dir(), 'image_link_audit_');

    if ($path === false) {
        throw new RuntimeException('Unable to create a temporary JSON file.');
    }

    file_put_contents($path, json_encode($records, JSON_THROW_ON_ERROR));

    return $path;
}

function imageLinkAuditPng(int $red, int $green, int $blue): string
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

function imageLinkAuditAttach(Item $item, string $bytes, array $customProperties = []): void
{
    $path = tempnam(sys_get_temp_dir(), 'image_link_media_');

    if ($path === false) {
        throw new RuntimeException('Unable to create a temporary media file.');
    }

    $pngPath = $path.'.png';
    @unlink($path);
    file_put_contents($pngPath, $bytes);
    $item->addMedia($pngPath)
        ->withCustomProperties($customProperties)
        ->toMediaCollection('images');
}

function imageLinkAuditOutputPath(): string
{
    return storage_path('app/testing/image-link-audit-'.Str::uuid());
}

/** @return list<array<string,string|null>> */
function imageLinkAuditCsvRows(string $path): array
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

test('it reports media provenance belonging to another product family without changing media', function (): void {
    $group = CarGroup::factory()->create(['name' => 'OPEL', 'excel_sheet_name' => 'OPEL']);
    $gm16 = imageLinkAuditRecord('GM 16');
    $gm18 = imageLinkAuditRecord('GM 18');
    $item = Item::factory()->create(['car_group_id' => $group->id, 'serial_code' => 'GM 16']);
    imageLinkAuditAttach($item, imageLinkAuditPng(100, 80, 60), [
        'source' => 'ecotrade',
        'source_url' => $gm18['main_image_url'],
        'source_hash' => imageLinkAuditSourceHash($gm18),
    ]);
    $media = $item->getFirstMedia('images');
    $mediaHash = hash_file('sha256', $media->getPath());
    $jsonPath = imageLinkAuditJson([$gm16, $gm18]);
    $outputPath = imageLinkAuditOutputPath();

    $this->artisan('media:audit-item-image-links', [
        'path' => $jsonPath,
        '--output' => $outputPath,
        '--code' => ['GM 16'],
    ])
        ->expectsOutputToContain('Confirmed wrong sources: 1')
        ->assertExitCode(0);

    $confirmedReport = file_get_contents($outputPath.'/confirmed_wrong_links.csv');
    expect($confirmedReport)
        ->toContain('confirmed_wrong_source')
        ->toContain($gm16['main_image_url'])
        ->toContain($gm18['main_image_url']);
    expect($item->refresh()->getFirstMedia('images')->getKey())->toBe($media->getKey())
        ->and(hash_file('sha256', $media->getPath()))->toBe($mediaHash);

    @unlink($jsonPath);
});

test('it reports identical image bytes reused by different product codes', function (): void {
    $group = CarGroup::factory()->create(['name' => 'OPEL', 'excel_sheet_name' => 'OPEL']);
    $sharedImage = imageLinkAuditPng(90, 70, 50);
    $first = Item::factory()->create(['car_group_id' => $group->id, 'serial_code' => 'GM 16']);
    $second = Item::factory()->create(['car_group_id' => $group->id, 'serial_code' => 'GM 18']);
    imageLinkAuditAttach($first, $sharedImage);
    imageLinkAuditAttach($second, $sharedImage);
    $jsonPath = imageLinkAuditJson([imageLinkAuditRecord('GM 16'), imageLinkAuditRecord('GM 18')]);
    $outputPath = imageLinkAuditOutputPath();

    $this->artisan('media:audit-item-image-links', [
        'path' => $jsonPath,
        '--output' => $outputPath,
    ])
        ->expectsOutputToContain('Cross-code image hashes: 1')
        ->assertExitCode(0);

    $reuseReport = file_get_contents($outputPath.'/cross_code_image_reuse.csv');
    expect($reuseReport)
        ->toContain('GM16')
        ->toContain('GM18')
        ->toContain((string) $first->id)
        ->toContain((string) $second->id);

    @unlink($jsonPath);
});

test('it performs visual comparison with PHP GD and keeps missing provenance explicit', function (): void {
    $group = CarGroup::factory()->create(['name' => 'OPEL', 'excel_sheet_name' => 'OPEL']);
    $record = imageLinkAuditRecord('GM 16');
    $referenceBytes = imageLinkAuditPng(120, 90, 60);
    $item = Item::factory()->create(['car_group_id' => $group->id, 'serial_code' => 'GM 16']);
    imageLinkAuditAttach($item, $referenceBytes);
    $jsonPath = imageLinkAuditJson([$record]);
    $outputPath = imageLinkAuditOutputPath();
    Http::fake([$record['main_image_url'] => Http::response($referenceBytes, 200, ['Content-Type' => 'image/png'])]);

    $this->artisan('media:audit-item-image-links', [
        'path' => $jsonPath,
        '--output' => $outputPath,
        '--visual' => true,
    ])
        ->expectsOutputToContain('visual match missing provenance: 1')
        ->assertExitCode(0);

    $auditRow = imageLinkAuditCsvRows($outputPath.'/image_link_audit.csv')[0];
    expect($auditRow['status'])->toBe('visual_match_missing_provenance')
        ->and((float) $auditRow['visual_score'])->toBe(1.0);
    Http::assertSentCount(1);

    @unlink($jsonPath);
});

test('it flags a low PHP visual score for untraceable media', function (): void {
    $group = CarGroup::factory()->create(['name' => 'OPEL', 'excel_sheet_name' => 'OPEL']);
    $record = imageLinkAuditRecord('GM 18');
    $item = Item::factory()->create(['car_group_id' => $group->id, 'serial_code' => 'GM 18']);
    imageLinkAuditAttach($item, imageLinkAuditPng(0, 0, 0));
    $jsonPath = imageLinkAuditJson([$record]);
    $outputPath = imageLinkAuditOutputPath();
    Http::fake([
        $record['main_image_url'] => Http::response(imageLinkAuditPng(255, 255, 255), 200, ['Content-Type' => 'image/png']),
    ]);

    $this->artisan('media:audit-item-image-links', [
        'path' => $jsonPath,
        '--output' => $outputPath,
        '--visual' => true,
        '--visual-threshold' => '0.90',
    ])
        ->expectsOutputToContain('likely visual mismatch: 1')
        ->assertExitCode(0);

    expect(file_get_contents($outputPath.'/visual_review.csv'))->toContain('likely_visual_mismatch');

    @unlink($jsonPath);
});

test('it does not report cross-code reuse when both codes belong to one Ecotrade reference', function (): void {
    $group = CarGroup::factory()->create(['name' => 'OPEL', 'excel_sheet_name' => 'OPEL']);
    $record = imageLinkAuditRecord('PRIMARY-100 ALT-200', ['product_name' => 'PRIMARY-100']);
    $sharedImage = imageLinkAuditPng(80, 60, 40);
    $primary = Item::factory()->create(['car_group_id' => $group->id, 'serial_code' => 'PRIMARY-100']);
    $alternate = Item::factory()->create(['car_group_id' => $group->id, 'serial_code' => 'ALT-200']);
    imageLinkAuditAttach($primary, $sharedImage);
    imageLinkAuditAttach($alternate, $sharedImage);
    $jsonPath = imageLinkAuditJson([$record]);
    $outputPath = imageLinkAuditOutputPath();

    $this->artisan('media:audit-item-image-links', [
        'path' => $jsonPath,
        '--output' => $outputPath,
    ])
        ->expectsOutputToContain('Cross-code image hashes: 0')
        ->assertExitCode(0);

    expect(file_get_contents($outputPath.'/cross_code_image_reuse.csv'))
        ->not->toContain((string) $primary->id)
        ->not->toContain((string) $alternate->id);

    @unlink($jsonPath);
});

test('it records reference download failures without aborting the data audit', function (): void {
    $group = CarGroup::factory()->create(['name' => 'OPEL', 'excel_sheet_name' => 'OPEL']);
    $record = imageLinkAuditRecord('GM 16');
    $item = Item::factory()->create(['car_group_id' => $group->id, 'serial_code' => 'GM 16']);
    imageLinkAuditAttach($item, imageLinkAuditPng(70, 50, 30));
    $jsonPath = imageLinkAuditJson([$record]);
    $outputPath = imageLinkAuditOutputPath();
    Http::fake([$record['main_image_url'] => Http::response([], 404)]);

    $this->artisan('media:audit-item-image-links', [
        'path' => $jsonPath,
        '--output' => $outputPath,
        '--visual' => true,
    ])->assertExitCode(0);

    $auditReport = file_get_contents($outputPath.'/image_link_audit.csv');
    expect($auditReport)
        ->toContain('missing_provenance')
        ->toContain('Unable to download Ecotrade source image');
    expect($item->refresh()->getFirstMedia('images'))->not->toBeNull();

    @unlink($jsonPath);
});

test('it reports media database rows whose original file is missing', function (): void {
    $group = CarGroup::factory()->create(['name' => 'OPEL', 'excel_sheet_name' => 'OPEL']);
    $record = imageLinkAuditRecord('GM 16');
    $item = Item::factory()->create(['car_group_id' => $group->id, 'serial_code' => 'GM 16']);
    imageLinkAuditAttach($item, imageLinkAuditPng(70, 50, 30));
    $media = $item->getFirstMedia('images');
    @unlink($media->getPath());
    $jsonPath = imageLinkAuditJson([$record]);
    $outputPath = imageLinkAuditOutputPath();

    $this->artisan('media:audit-item-image-links', [
        'path' => $jsonPath,
        '--output' => $outputPath,
    ])->assertExitCode(0);

    expect(file_get_contents($outputPath.'/visual_review.csv'))
        ->toContain('missing_media_file')
        ->toContain((string) $media->getKey());
    expect($item->refresh()->getFirstMedia('images')->getKey())->toBe($media->getKey());

    @unlink($jsonPath);
});

test('it reports ambiguous Ecotrade references without choosing an image', function (): void {
    $group = CarGroup::factory()->create(['name' => 'OPEL', 'excel_sheet_name' => 'OPEL']);
    $firstReference = imageLinkAuditRecord('GM 16');
    $secondReference = imageLinkAuditRecord('GM 16', [
        'product_url' => 'https://www.ecotradegroup.com/en/product/opel-vauxhall/gm-16-alternative',
        'main_image_url' => 'https://images.test/gm-16-alternative.png',
        'image_urls' => ['https://images.test/gm-16-alternative.png'],
    ]);
    $item = Item::factory()->create(['car_group_id' => $group->id, 'serial_code' => 'GM 16']);
    imageLinkAuditAttach($item, imageLinkAuditPng(70, 50, 30));
    $jsonPath = imageLinkAuditJson([$firstReference, $secondReference]);
    $outputPath = imageLinkAuditOutputPath();

    $this->artisan('media:audit-item-image-links', [
        'path' => $jsonPath,
        '--output' => $outputPath,
        '--visual' => true,
    ])->assertExitCode(0);

    $auditRow = imageLinkAuditCsvRows($outputPath.'/visual_review.csv')[0];
    expect($auditRow['status'])->toBe('ambiguous_reference')
        ->and((int) $auditRow['reference_count'])->toBe(2);
    Http::assertNothingSent();

    @unlink($jsonPath);
});
