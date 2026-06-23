<?php

use App\Models\CarGroup;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('repairs ecotrade item details from json payloads into plain text', function () {
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Unidentified',
        'excel_sheet_name' => 'UNIDENTIFIED',
        'region' => null,
        'source' => 'ecotrade',
    ]);

    $badItem = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => '103R 10 0013 CX SHA 00',
        'serial_code' => 'A33-1205010-01',
        'normalized_serial' => Item::normalizeSerialValue('A33-1205010-01'),
        'details' => json_encode([
            'source' => 'ecotrade',
            'brand_slug' => 'unidentified',
            'brand_name' => 'Unidentified',
            'product_url' => 'https://www.ecotradegroup.com/en/product/unidentified/103r-10-0013-cx-sha-00',
            'brand_page_url' => 'https://www.ecotradegroup.com/en/carbrand/unidentified/30',
            'product_name' => '103R 10 0013 CX SHA 00',
            'serial_code' => 'A33-1205010-01',
            'thumbnail_url' => 'https://www.ecotradegroup.com/cache/product_thumb/uploads/products/11430/path-7fa-.png',
            'main_image_url' => 'https://www.ecotradegroup.com/cache/product_thumb/uploads/products/11430/path-7fa-.png',
            'image_urls' => ['https://www.ecotradegroup.com/cache/product_thumb/uploads/products/11430/path-7fa-.png'],
            'image_count' => 1,
            'card_price' => null,
            'card_texts' => ['Metals content', 'A33-1205010-01'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'source' => 'ecotrade',
    ]);

    $goodItem = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'Already Plain',
        'serial_code' => 'PLAIN-1',
        'normalized_serial' => Item::normalizeSerialValue('PLAIN-1'),
        'details' => 'already plain text',
        'source' => 'ecotrade',
    ]);

    $this->artisan('items:repair-ecotrade-details')
        ->expectsOutputToContain('rows_scanned: 2')
        ->expectsOutputToContain('rows_updated: 1')
        ->assertExitCode(0);

    $badItem->refresh();
    $goodItem->refresh();

    expect($badItem->details)->toBe('A33-1205010-01')
        ->and($goodItem->details)->toBe('already plain text');
});
