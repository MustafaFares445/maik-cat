<?php

use App\Models\CarGroup;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Downloaders\HttpFacadeDownloader;

uses(RefreshDatabase::class);

function ecotradeTempFile(string $contents, string $suffix): string
{
    $base = tempnam(sys_get_temp_dir(), 'ecotrade_');

    if ($base === false) {
        throw new RuntimeException('Failed to allocate a temporary Ecotrade file.');
    }

    @unlink($base);

    $path = $base.$suffix;
    file_put_contents($path, $contents);

    return $path;
}

function ecotradeSampleJson(): array
{
    return [
        [
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
        ],
        [
            'product_url' => 'https://www.ecotradegroup.com/en/product/alfa-romeo/al-1914710107',
            'brand_page_url' => 'https://www.ecotradegroup.com/en/carbrand/alfa-romeo/2',
            'brand_slug' => '2',
            'brand' => '2',
            'serial_code' => 'AL 1914710107',
            'product_name' => 'AL 1914710107',
            'thumbnail_url' => 'https://www.ecotradegroup.com/cache/product_thumb/uploads/products/34398/path-c05-81279.png',
            'card_price' => '',
            'card_texts' => ['Metals content', 'AL 1914710107'],
            'image_urls' => ['https://www.ecotradegroup.com/cache/product_thumb/uploads/products/34398/path-c05-81279.png'],
            'main_image_url' => 'https://www.ecotradegroup.com/cache/product_thumb/uploads/products/34398/path-c05-81279.png',
            'image_count' => 1,
        ],
        [
            'product_url' => 'https://www.ecotradegroup.com/en/product/acura/acura-mdx-07-front',
            'brand_page_url' => 'https://www.ecotradegroup.com/en/carbrand/acura',
            'brand_slug' => 'acura',
            'brand' => 'acura',
            'serial_code' => 'ACURA MDX 07 UPFRONT',
            'product_name' => 'ACURA MDX 07 FRONT',
            'thumbnail_url' => 'https://www.ecotradegroup.com/cache/product_thumb/uploads/products/32250/path-22e-.png',
            'card_price' => '',
            'card_texts' => ['Metals content', 'ACURA MDX 07 UPFRONT'],
            'image_urls' => ['https://www.ecotradegroup.com/cache/product_thumb/uploads/products/32250/path-22e-.png'],
            'main_image_url' => 'https://www.ecotradegroup.com/cache/product_thumb/uploads/products/32250/path-22e-.png',
            'image_count' => 1,
        ],
    ];
}

function ecotradePngBytes(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7Wf8kAAAAASUVORK5CYII=',
        true
    ) ?: '';
}

beforeEach(function (): void {
    Storage::fake('public');
    Config::set('media-library.media_downloader', HttpFacadeDownloader::class);
});

test('imports Ecotrade brands, products, and brand logos', function () {
    $jsonPath = ecotradeTempFile(json_encode(ecotradeSampleJson(), JSON_THROW_ON_ERROR), '.json');
    $mappingPath = ecotradeTempFile(json_encode([
        'acura' => 'https://images.test/logos/acura.png',
        'alfa-romeo' => 'https://images.test/logos/alfa-romeo.png',
    ], JSON_THROW_ON_ERROR), '.json');

    Http::fake([
        'https://images.test/logos/acura.png' => Http::response(ecotradePngBytes(), 200, [
            'Content-Type' => 'image/png',
        ]),
        'https://images.test/logos/alfa-romeo.png' => Http::response(ecotradePngBytes(), 200, [
            'Content-Type' => 'image/png',
        ]),
    ]);

    $this->artisan('ecotrade:import-json', [
        'path' => $jsonPath,
        '--brand-images' => $mappingPath,
    ])->assertExitCode(0);

    expect(CarGroup::query()->where('source', 'ecotrade')->count())->toBe(2)
        ->and(Item::query()->where('source', 'ecotrade')->count())->toBe(3);

    $alfaRomeo = CarGroup::query()->where('slug', 'alfa-romeo')->firstOrFail();
    expect($alfaRomeo->name)->toBe('Alfa Romeo')
        ->and($alfaRomeo->excel_sheet_name)->toBe('ALFA ROMEO')
        ->and($alfaRomeo->getFirstMedia('logo'))->not->toBeNull();

    $item = Item::query()->where('source_hash', sha1('acura|ACURA MDX 04 FRONT|https://www.ecotradegroup.com/en/product/acura/acura-mdx-04-front'))->firstOrFail();
    $details = json_decode((string) $item->details, true, 512, JSON_THROW_ON_ERROR);

    expect($details['brand_slug'])->toBe('acura')
        ->and($details['image_urls'])->toBe([
            'https://www.ecotradegroup.com/cache/product_thumb/uploads/products/32248/path-10d-.png',
        ])
        ->and($item->media()->count())->toBe(0);

    @unlink($jsonPath);
    @unlink($mappingPath);
});

test('running Ecotrade import twice does not duplicate brands or products', function () {
    $jsonPath = ecotradeTempFile(json_encode(ecotradeSampleJson(), JSON_THROW_ON_ERROR), '.json');

    $this->artisan('ecotrade:import-json', [
        'path' => $jsonPath,
    ])->assertExitCode(0);

    $brandCount = CarGroup::query()->count();
    $itemCount = Item::query()->count();

    $this->artisan('ecotrade:import-json', [
        'path' => $jsonPath,
    ])->assertExitCode(0);

    expect(CarGroup::query()->count())->toBe($brandCount)
        ->and(Item::query()->count())->toBe($itemCount);

    @unlink($jsonPath);
});

test('dry run validates Ecotrade import without writing data', function () {
    $jsonPath = ecotradeTempFile(json_encode(ecotradeSampleJson(), JSON_THROW_ON_ERROR), '.json');

    $this->artisan('ecotrade:import-json', [
        'path' => $jsonPath,
        '--dry-run' => true,
    ])->assertExitCode(0);

    expect(CarGroup::query()->count())->toBe(0)
        ->and(Item::query()->count())->toBe(0);

    @unlink($jsonPath);
});
