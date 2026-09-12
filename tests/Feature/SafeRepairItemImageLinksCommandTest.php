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

function safeRepairSharedPng(): string
{
    $image = imagecreatetruecolor(80, 60);
    $background = imagecolorallocate($image, 110, 90, 70);
    imagefill($image, 0, 0, $background);
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    imagedestroy($image);

    if (! is_string($bytes)) {
        throw new RuntimeException('Unable to create PNG bytes.');
    }

    return $bytes;
}

function safeRepairAttach(Item $item, string $bytes, array $properties, string $fileName): mixed
{
    $path = tempnam(sys_get_temp_dir(), 'safe_repair_media_');

    if ($path === false) {
        throw new RuntimeException('Unable to create media temp file.');
    }

    $pngPath = $path.'.png';
    @unlink($path);
    file_put_contents($pngPath, $bytes);

    return $item
        ->addMedia($pngPath)
        ->usingFileName($fileName)
        ->withCustomProperties($properties)
        ->toMediaCollection('images');
}

function safeRepairAuditReport(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'safe_repair_report_');

    if ($path === false) {
        throw new RuntimeException('Unable to create report temp file.');
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
        throw new RuntimeException('Unable to open report temp file.');
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

function safeRepairOutputPath(): string
{
    return storage_path('app/testing/safe-image-repair-'.Str::uuid());
}

test('it excludes cross-code reused images from donors and adds them to unsolved review', function (): void {
    $group = CarGroup::factory()->create(['name' => 'TEST', 'excel_sheet_name' => 'TEST']);
    $sharedBytes = safeRepairSharedPng();
    $sharedSha = hash('sha256', $sharedBytes);
    $hashA = sha1('test|A100|https://products.test/a100');
    $hashB = sha1('test|B200|https://products.test/b200');

    $first = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'A100',
        'source_url' => 'https://products.test/a100',
        'source_hash' => $hashA,
    ]);
    $firstMedia = safeRepairAttach($first, $sharedBytes, [
        'source' => 'ecotrade',
        'source_hash' => $hashA,
        'source_url' => 'https://images.test/a100.png',
        'gemini_result' => 'edited',
    ], 'a100-maikcat.png');

    $second = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'B200',
        'source_url' => 'https://products.test/b200',
        'source_hash' => $hashB,
    ]);
    $secondMedia = safeRepairAttach($second, $sharedBytes, [
        'source' => 'ecotrade',
        'source_hash' => $hashB,
        'source_url' => 'https://images.test/b200.png',
        'gemini_result' => 'edited',
    ], 'b200-maikcat.png');

    $target = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'A100',
        'source_url' => 'https://products.test/a100',
        'source_hash' => $hashA,
    ]);
    $missingMedia = safeRepairAttach($target, safeRepairSharedPng(), [
        'source' => 'ecotrade',
        'source_hash' => $hashA,
        'source_url' => 'https://images.test/a100.png',
    ], 'missing-a100.png');
    @unlink($missingMedia->getPath());

    $report = safeRepairAuditReport([
        [
            'status' => 'provenance_match',
            'item_id' => $first->id,
            'car_group_id' => $group->id,
            'car_group' => 'TEST',
            'serial_code' => 'A100',
            'normalized_serial' => 'A100',
            'primary_family_key' => $group->id.'|A100',
            'reference_count' => '1',
            'item_source_url' => 'https://products.test/a100',
            'item_source_hash' => $hashA,
            'media_id' => $firstMedia->id,
            'media_file_name' => $firstMedia->file_name,
            'media_sha256' => $sharedSha,
            'media_source_url' => 'https://images.test/a100.png',
            'media_source_hash' => $hashA,
            'expected_product_url' => 'https://products.test/a100',
            'expected_image_url' => 'https://images.test/a100.png',
            'expected_source_hash' => $hashA,
        ],
        [
            'status' => 'provenance_match',
            'item_id' => $second->id,
            'car_group_id' => $group->id,
            'car_group' => 'TEST',
            'serial_code' => 'B200',
            'normalized_serial' => 'B200',
            'primary_family_key' => $group->id.'|B200',
            'reference_count' => '1',
            'item_source_url' => 'https://products.test/b200',
            'item_source_hash' => $hashB,
            'media_id' => $secondMedia->id,
            'media_file_name' => $secondMedia->file_name,
            'media_sha256' => $sharedSha,
            'media_source_url' => 'https://images.test/b200.png',
            'media_source_hash' => $hashB,
            'expected_product_url' => 'https://products.test/b200',
            'expected_image_url' => 'https://images.test/b200.png',
            'expected_source_hash' => $hashB,
        ],
        [
            'status' => 'missing_media_file',
            'item_id' => $target->id,
            'car_group_id' => $group->id,
            'car_group' => 'TEST',
            'serial_code' => 'A100',
            'normalized_serial' => 'A100',
            'primary_family_key' => $group->id.'|A100',
            'reference_count' => '1',
            'item_source_url' => 'https://products.test/a100',
            'item_source_hash' => $hashA,
            'media_id' => $missingMedia->id,
            'media_file_name' => $missingMedia->file_name,
            'media_sha256' => '',
            'media_source_url' => 'https://images.test/a100.png',
            'media_source_hash' => $hashA,
            'expected_product_url' => 'https://products.test/a100',
            'expected_image_url' => 'https://images.test/a100.png',
            'expected_source_hash' => $hashA,
        ],
    ]);
    $output = safeRepairOutputPath();

    $this->artisan('media:repair-item-image-links-safe', [
        'report' => $report,
        '--output' => $output,
    ])
        ->expectsOutputToContain('Repairable from existing images: 0')
        ->expectsOutputToContain('Suspicious cross-code image hashes: 1')
        ->expectsOutputToContain('Cross-code rows added to unsolved review: 2')
        ->assertExitCode(0);

    $unsolved = file_get_contents($output.'/unsolved_images.csv');
    expect($unsolved)
        ->toContain((string) $first->id)
        ->toContain((string) $second->id)
        ->toContain((string) $target->id)
        ->toContain('cross_code_image_reuse_requires_review')
        ->toContain('no_trusted_existing_project_image');

    $summary = json_decode((string) file_get_contents($output.'/summary.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($summary['cross_code_image_hashes'])->toBe(1)
        ->and($summary['cross_code_review_rows'])->toBe(2)
        ->and($summary['unsolved'])->toBe(3);

    @unlink($report);
});
