<?php

use App\Models\CarGroup;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function ecotradeImageImportTempFile(string $contents, string $suffix): string
{
    $base = tempnam(sys_get_temp_dir(), 'ecotrade_images_');

    if ($base === false) {
        throw new RuntimeException('Failed to allocate a temporary Ecotrade image import file.');
    }

    @unlink($base);
    $path = $base.$suffix;
    file_put_contents($path, $contents);

    return $path;
}

function ecotradeImageImportPngBytes(int $width = 320, int $height = 220): string
{
    $image = imagecreatetruecolor($width, $height);
    $background = imagecolorallocate($image, 245, 245, 245);
    $metal = imagecolorallocate($image, 120, 120, 120);
    $rust = imagecolorallocate($image, 132, 76, 38);

    imagefill($image, 0, 0, $background);
    imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width * 0.62), (int) ($height * 0.36), $metal);
    imagefilledellipse($image, (int) ($width / 2.4), (int) ($height / 2.1), (int) ($width * 0.18), (int) ($height * 0.12), $rust);

    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

function ecotradeImageImportRecord(array $overrides = []): array
{
    return array_merge([
        'product_url' => 'https://www.ecotradegroup.com/en/product/acura/acura-mdx-04-front',
        'brand_page_url' => 'https://www.ecotradegroup.com/en/carbrand/acura',
        'brand_slug' => 'acura',
        'brand' => 'acura',
        'serial_code' => 'ACURA MDX 04 FRONT',
        'product_name' => 'ACURA MDX 04 FRONT',
        'thumbnail_url' => 'https://images.test/source/acura-thumb.png',
        'card_price' => '',
        'card_texts' => ['Metals content', 'ACURA MDX 04 FRONT'],
        'image_urls' => ['https://images.test/source/acura.png'],
        'main_image_url' => 'https://images.test/source/acura.png',
        'image_count' => 1,
    ], $overrides);
}

function ecotradeImageImportHash(string $brandSlug, string $serialCode, string $productUrl): string
{
    return sha1(mb_strtolower($brandSlug).'|'.mb_strtoupper($serialCode).'|'.mb_strtolower($productUrl));
}

function ecotradeImageImportGroup(array $overrides = []): CarGroup
{
    return CarGroup::factory()->create(array_merge([
        'name' => 'Acura',
        'excel_sheet_name' => 'ACURA',
        'slug' => 'acura',
        'source' => 'ecotrade',
    ], $overrides));
}

function ecotradeImageImportItem(array $record, array $overrides = [], ?CarGroup $group = null): Item
{
    $group ??= ecotradeImageImportGroup();

    return Item::factory()->create(array_merge([
        'car_group_id' => $group->id,
        'model' => $record['product_name'],
        'serial_code' => $record['serial_code'],
        'weight_kg' => 1.234,
        'pt_ppm' => 150.5,
        'pd_ppm' => 220.25,
        'rh_ppm' => 12.75,
        'source' => 'ecotrade',
        'source_url' => $record['product_url'],
        'source_hash' => ecotradeImageImportHash($record['brand_slug'], $record['serial_code'], $record['product_url']),
    ], $overrides));
}

function ecotradeGeminiResponse(string $bytes): array
{
    return [
        'candidates' => [
            [
                'content' => [
                    'parts' => [
                        [
                            'inlineData' => [
                                'mimeType' => 'image/png',
                                'data' => base64_encode($bytes),
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function attachExistingImage(Item $item): void
{
    $path = ecotradeImageImportTempFile(ecotradeImageImportPngBytes(), '.png');
    $item->addMedia($path)->usingFileName('existing.png')->toMediaCollection('images');
}

beforeEach(function (): void {
    Storage::fake('public');
    Config::set('services.gemini.api_key', 'test-gemini-key');
    Config::set('services.gemini.image_model', 'gemini-2.5-flash-image');
    Config::set('services.gemini.image_cost_usd', 0.039387);
    Config::set('media-library.max_file_size', 1024 * 1024 * 10);
});

test('dry run reports priceable image candidates without external calls', function () {
    $record = ecotradeImageImportRecord();
    $item = ecotradeImageImportItem($record);
    $jsonPath = ecotradeImageImportTempFile(json_encode([$record], JSON_THROW_ON_ERROR), '.json');

    Http::fake();

    $this->artisan('ecotrade:import-product-images', [
        'path' => $jsonPath,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('candidates selected: 1')
        ->expectsOutputToContain('estimated gemini cost usd: $0.0394')
        ->assertExitCode(0);

    Http::assertNothingSent();
    expect($item->refresh()->getFirstMedia('images'))->toBeNull();

    @unlink($jsonPath);
});

test('paid image import requires an explicit cost ceiling', function () {
    $record = ecotradeImageImportRecord();
    ecotradeImageImportItem($record);
    $jsonPath = ecotradeImageImportTempFile(json_encode([$record], JSON_THROW_ON_ERROR), '.json');

    Http::fake();

    $this->artisan('ecotrade:import-product-images', ['path' => $jsonPath])
        ->expectsOutputToContain('Paid run requires --max-cost-usd')
        ->assertExitCode(1);

    Http::assertNothingSent();
    @unlink($jsonPath);
});

test('successful processing stores cleaned media using the serial filename', function () {
    $record = ecotradeImageImportRecord();
    $item = ecotradeImageImportItem($record);
    $jsonPath = ecotradeImageImportTempFile(json_encode([$record], JSON_THROW_ON_ERROR), '.json');
    $sourceBytes = ecotradeImageImportPngBytes();
    $editedBytes = ecotradeImageImportPngBytes(360, 240);

    Http::fake([
        'https://images.test/source/acura.png' => Http::response($sourceBytes, 200, ['Content-Type' => 'image/png']),
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent*' => Http::response(
            ecotradeGeminiResponse($editedBytes),
            200,
        ),
    ]);

    $this->artisan('ecotrade:import-product-images', [
        'path' => $jsonPath,
        '--test' => true,
    ])
        ->expectsOutputToContain('Test result:')
        ->expectsOutputToContain('Media URL:')
        ->assertExitCode(0);

    $media = $item->refresh()->getFirstMedia('images');

    expect($media)->not->toBeNull()
        ->and($media->file_name)->toBe('acura-mdx-04-front-maikcat.png')
        ->and($media->getCustomProperty('source'))->toBe('ecotrade')
        ->and($media->getCustomProperty('gemini_result'))->toBe('edited')
        ->and($media->getCustomProperty('watermark_mode'))->toBe('spatie')
        ->and($media->getCustomProperty('maikcat_watermark'))->toBeTrue();

    Http::assertSentCount(2);
    @unlink($jsonPath);
});

test('Gemini text-only response does not attach the original supplier image', function () {
    $record = ecotradeImageImportRecord();
    $item = ecotradeImageImportItem($record);
    $jsonPath = ecotradeImageImportTempFile(json_encode([$record], JSON_THROW_ON_ERROR), '.json');

    Http::fake([
        'https://images.test/source/acura.png' => Http::response(
            ecotradeImageImportPngBytes(),
            200,
            ['Content-Type' => 'image/png'],
        ),
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent*' => Http::response([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'No edited image was generated.'],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('ecotrade:import-product-images', [
        'path' => $jsonPath,
        '--test' => true,
    ])
        ->expectsOutputToContain('Image failed for item '.$item->id)
        ->expectsOutputToContain('Imported: 0')
        ->expectsOutputToContain('Failed: 1')
        ->assertExitCode(1);

    expect($item->refresh()->getFirstMedia('images'))->toBeNull();
    Http::assertSentCount(2);

    @unlink($jsonPath);
});

test('one processed image is copied to every assay item in the serial family', function () {
    $group = ecotradeImageImportGroup();
    $record = ecotradeImageImportRecord([
        'serial_code' => 'FAMILY-100',
        'product_name' => 'FAMILY-100',
        'product_url' => 'https://www.ecotradegroup.com/en/product/acura/family-100',
        'main_image_url' => 'https://images.test/source/family-100.png',
        'image_urls' => ['https://images.test/source/family-100.png'],
    ]);

    $first = ecotradeImageImportItem($record, [], $group);
    $second = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'FAMILY-100 second assay',
        'serial_code' => 'FAMILY 100',
        'weight_kg' => 1.4,
        'pt_ppm' => 180,
        'pd_ppm' => 240,
        'rh_ppm' => 14,
        'source' => 'excel_import',
    ]);

    $jsonPath = ecotradeImageImportTempFile(json_encode([$record], JSON_THROW_ON_ERROR), '.json');

    Http::fake([
        'https://images.test/source/family-100.png' => Http::response(
            ecotradeImageImportPngBytes(),
            200,
            ['Content-Type' => 'image/png'],
        ),
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent*' => Http::response(
            ecotradeGeminiResponse(ecotradeImageImportPngBytes(360, 240)),
            200,
        ),
    ]);

    $this->artisan('ecotrade:import-product-images', [
        'path' => $jsonPath,
        '--max-cost-usd' => '0.05',
    ])->assertExitCode(0);

    expect($first->refresh()->hasMedia('images'))->toBeTrue()
        ->and($second->refresh()->hasMedia('images'))->toBeTrue()
        ->and($second->getFirstMedia('images')->getCustomProperty('source_url'))
        ->toBe('https://images.test/source/family-100.png');

    @unlink($jsonPath);
});

test('placeholder and mascot URLs are rejected before image processing', function () {
    $record = ecotradeImageImportRecord([
        'serial_code' => 'PLACEHOLDER-1',
        'product_name' => 'PLACEHOLDER-1',
        'main_image_url' => 'https://www.ecotradegroup.com/build/assets/website/images/mascots/mascote_en.jpg',
        'image_urls' => [],
    ]);
    ecotradeImageImportItem($record);
    $jsonPath = ecotradeImageImportTempFile(json_encode([$record], JSON_THROW_ON_ERROR), '.json');

    Http::fake();

    $this->artisan('ecotrade:import-product-images', [
        'path' => $jsonPath,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('records rejected placeholder image: 1')
        ->expectsOutputToContain('candidates selected: 0')
        ->assertExitCode(0);

    Http::assertNothingSent();
    @unlink($jsonPath);
});

test('existing family image is skipped and can be copied with the sibling sync command', function () {
    $group = ecotradeImageImportGroup();
    $record = ecotradeImageImportRecord(['serial_code' => 'EXISTING-IMAGE']);
    $source = ecotradeImageImportItem($record, [], $group);
    attachExistingImage($source);

    $sibling = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'second analysis',
        'serial_code' => 'EXISTING IMAGE',
        'weight_kg' => 1.5,
        'pt_ppm' => 200,
        'pd_ppm' => null,
        'rh_ppm' => null,
        'source' => 'excel_import',
    ]);

    $jsonPath = ecotradeImageImportTempFile(json_encode([$record], JSON_THROW_ON_ERROR), '.json');

    $this->artisan('ecotrade:import-product-images', [
        'path' => $jsonPath,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('skipped existing image: 1')
        ->expectsOutputToContain('candidates selected: 0')
        ->assertExitCode(0);

    $this->artisan('items:sync-sibling-images')->assertExitCode(0);

    expect($sibling->refresh()->hasMedia('images'))->toBeTrue();
    @unlink($jsonPath);
});

test('the image command continues after one candidate fails', function () {
    $group = ecotradeImageImportGroup();
    $failedRecord = ecotradeImageImportRecord([
        'product_url' => 'https://www.ecotradegroup.com/en/product/acura/fail',
        'serial_code' => 'FAIL-IMAGE',
        'product_name' => 'FAIL-IMAGE',
        'main_image_url' => 'https://images.test/source/fail.png',
        'image_urls' => ['https://images.test/source/fail.png'],
    ]);
    $validRecord = ecotradeImageImportRecord([
        'product_url' => 'https://www.ecotradegroup.com/en/product/acura/valid',
        'serial_code' => 'VALID-IMAGE',
        'product_name' => 'VALID-IMAGE',
        'main_image_url' => 'https://images.test/source/valid.png',
        'image_urls' => ['https://images.test/source/valid.png'],
    ]);

    $failedItem = ecotradeImageImportItem($failedRecord, [], $group);
    $validItem = ecotradeImageImportItem($validRecord, [], $group);
    $jsonPath = ecotradeImageImportTempFile(
        json_encode([$failedRecord, $validRecord], JSON_THROW_ON_ERROR),
        '.json',
    );

    Http::fake([
        'https://images.test/source/fail.png' => Http::failedConnection('cURL error 28: timed out'),
        'https://images.test/source/valid.png' => Http::response(
            ecotradeImageImportPngBytes(),
            200,
            ['Content-Type' => 'image/png'],
        ),
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent*' => Http::response(
            ecotradeGeminiResponse(ecotradeImageImportPngBytes(360, 240)),
            200,
        ),
    ]);

    $this->artisan('ecotrade:import-product-images', [
        'path' => $jsonPath,
        '--max-cost-usd' => '0.10',
    ])
        ->expectsOutputToContain('Processed: 2')
        ->expectsOutputToContain('Imported: 1')
        ->expectsOutputToContain('Failed: 1')
        ->assertExitCode(0);

    expect($failedItem->refresh()->hasMedia('images'))->toBeFalse()
        ->and($validItem->refresh()->hasMedia('images'))->toBeTrue();

    @unlink($jsonPath);
});

test('AI watermark mode includes the requested Maik Cat instructions', function () {
    $record = ecotradeImageImportRecord();
    ecotradeImageImportItem($record);
    $jsonPath = ecotradeImageImportTempFile(json_encode([$record], JSON_THROW_ON_ERROR), '.json');
    $prompt = null;

    Http::fake(function (Request $request) use (&$prompt) {
        if ($request->url() === 'https://images.test/source/acura.png') {
            return Http::response(ecotradeImageImportPngBytes(), 200, ['Content-Type' => 'image/png']);
        }

        if (str_contains($request->url(), 'generativelanguage.googleapis.com')) {
            $prompt = $request->data()['contents'][0]['parts'][0]['text'] ?? null;

            return Http::response(ecotradeGeminiResponse(ecotradeImageImportPngBytes()), 200);
        }

        return Http::response([], 404);
    });

    $this->artisan('ecotrade:import-product-images', [
        'path' => $jsonPath,
        '--test' => true,
        '--watermark-ai' => true,
    ])->assertExitCode(0);

    expect($prompt)->toContain('maikcat')
        ->and($prompt)->toContain('Add multiple visible repeated watermarks')
        ->and($prompt)->toContain('do not add any source attribution');

    @unlink($jsonPath);
});
