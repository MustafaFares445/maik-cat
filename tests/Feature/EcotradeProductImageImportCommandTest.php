<?php

use App\Models\CarGroup;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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

function ecotradeImageImportItem(array $record, array $overrides = []): Item
{
    $group = CarGroup::factory()->create([
        'name' => 'Acura',
        'excel_sheet_name' => 'ACURA',
        'slug' => 'acura',
        'source' => 'ecotrade',
    ]);

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

beforeEach(function (): void {
    Storage::fake('public');
    Config::set('services.gemini.api_key', 'test-gemini-key');
    Config::set('services.gemini.image_model', 'gemini-2.5-flash-image');
    Config::set('services.gemini.image_cost_usd', 0.039387);
    Config::set('media-library.max_file_size', 1024 * 1024 * 10);
});

test('dry run reports priceable image candidates without calling Gemini or writing media', function () {
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
    expect($item->refresh()->media()->count())->toBe(0);

    @unlink($jsonPath);
});

test('paid full run requires an explicit max cost guard', function () {
    $record = ecotradeImageImportRecord();
    ecotradeImageImportItem($record);
    $jsonPath = ecotradeImageImportTempFile(json_encode([$record], JSON_THROW_ON_ERROR), '.json');

    Http::fake();

    $this->artisan('ecotrade:import-product-images', [
        'path' => $jsonPath,
    ])
        ->expectsOutputToContain('Paid run requires --max-cost-usd')
        ->assertExitCode(1);

    Http::assertNothingSent();

    @unlink($jsonPath);
});

test('test mode processes one image and stores the Gemini result in item media', function () {
    $record = ecotradeImageImportRecord();
    $item = ecotradeImageImportItem($record);
    $jsonPath = ecotradeImageImportTempFile(json_encode([$record], JSON_THROW_ON_ERROR), '.json');
    $sourceBytes = ecotradeImageImportPngBytes();
    $editedBytes = ecotradeImageImportPngBytes(360, 240);

    Http::fake([
        'https://images.test/source/acura.png' => Http::response($sourceBytes, 200, [
            'Content-Type' => 'image/png',
        ]),
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent*' => Http::response([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'inlineData' => [
                                    'mimeType' => 'image/png',
                                    'data' => base64_encode($editedBytes),
                                ],
                            ],
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
        ->expectsOutputToContain('Test mode: yes')
        ->expectsOutputToContain('Test result:')
        ->expectsOutputToContain('Media URL:')
        ->assertExitCode(0);

    $media = $item->refresh()->getFirstMedia('images');

    expect($media)->not->toBeNull()
        ->and($media->getCustomProperty('source'))->toBe('ecotrade')
        ->and($media->getCustomProperty('source_url'))->toBe('https://images.test/source/acura.png')
        ->and($media->getCustomProperty('gemini_model'))->toBe('gemini-2.5-flash-image')
        ->and($media->getCustomProperty('watermark_mode'))->toBe('spatie')
        ->and($media->getCustomProperty('maikcat_watermark'))->toBeTrue();

    Http::assertSentCount(2);

    @unlink($jsonPath);
});

test('ai watermark mode sends maikcat instruction to Gemini', function () {
    $record = ecotradeImageImportRecord();
    ecotradeImageImportItem($record);
    $jsonPath = ecotradeImageImportTempFile(json_encode([$record], JSON_THROW_ON_ERROR), '.json');
    $sourceBytes = ecotradeImageImportPngBytes();
    $editedBytes = ecotradeImageImportPngBytes();
    $geminiPrompt = null;

    Http::fake(function (Request $request) use ($sourceBytes, $editedBytes, &$geminiPrompt) {
        if ($request->url() === 'https://images.test/source/acura.png') {
            return Http::response($sourceBytes, 200, ['Content-Type' => 'image/png']);
        }

        if (str_contains($request->url(), 'generativelanguage.googleapis.com')) {
            $parts = $request->data()['contents'][0]['parts'] ?? [];
            $geminiPrompt = $parts[0]['text'] ?? null;

            return Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'inlineData' => [
                                        'mimeType' => 'image/png',
                                        'data' => base64_encode($editedBytes),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200);
        }

        return Http::response([], 404);
    });

    $this->artisan('ecotrade:import-product-images', [
        'path' => $jsonPath,
        '--test' => true,
        '--watermark-ai' => true,
    ])->assertExitCode(0);

    expect($geminiPrompt)->toContain('maikcat')
        ->and($geminiPrompt)->toContain('add multiple visible repeated watermarks');

    @unlink($jsonPath);
});

test('watermark strategy aliases are mutually exclusive', function () {
    $record = ecotradeImageImportRecord();
    ecotradeImageImportItem($record);
    $jsonPath = ecotradeImageImportTempFile(json_encode([$record], JSON_THROW_ON_ERROR), '.json');

    Http::fake();

    $this->artisan('ecotrade:import-product-images', [
        'path' => $jsonPath,
        '--watermark-ai' => true,
        '--watermark-spatie' => true,
    ])
        ->expectsOutputToContain('Use only one watermark strategy')
        ->assertExitCode(1);

    Http::assertNothingSent();

    @unlink($jsonPath);
});

test('already imaged items are skipped unless replace existing is enabled', function () {
    $record = ecotradeImageImportRecord();
    $item = ecotradeImageImportItem($record);
    $jsonPath = ecotradeImageImportTempFile(json_encode([$record], JSON_THROW_ON_ERROR), '.json');
    $existingPath = ecotradeImageImportTempFile(ecotradeImageImportPngBytes(), '.png');

    $item->addMedia($existingPath)->toMediaCollection('images');

    Http::fake();

    $this->artisan('ecotrade:import-product-images', [
        'path' => $jsonPath,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('skipped existing image: 1')
        ->expectsOutputToContain('candidates selected: 0')
        ->assertExitCode(0);

    Http::assertNothingSent();

    @unlink($jsonPath);
});
