<?php

use App\Models\CarGroup;
use App\Models\ExtraCode;
use App\Models\Item;
use App\Services\Ecotrade\EcotradeProductImageCandidateResolver;
use App\Support\Items\CatalystSerialValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('catalyst serial validator rejects control and placeholder serials', function () {
    expect(CatalystSerialValidator::isUsable(null))->toBeFalse()
        ->and(CatalystSerialValidator::isUsable(''))->toBeFalse()
        ->and(CatalystSerialValidator::isUsable('?'))->toBeFalse()
        ->and(CatalystSerialValidator::isUsable('??...'))->toBeFalse()
        ->and(CatalystSerialValidator::isUsable('\\'))->toBeFalse()
        ->and(CatalystSerialValidator::isUsable('KONTROLINIS'))->toBeFalse()
        ->and(CatalystSerialValidator::isUsable('unknown'))->toBeFalse()
        ->and(CatalystSerialValidator::isUsable('8K0-131-701'))->toBeTrue();
});

test('Ecotrade image resolver matches an item through an extra code', function () {
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'ACURA',
        'excel_sheet_name' => 'ACURA',
        'region' => 'Japanese',
    ]);

    $item = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'ACURA alternate code',
        'serial_code' => 'PRIMARY-100',
        'weight_kg' => 1.2,
        'pt_ppm' => 100,
        'pd_ppm' => 200,
        'rh_ppm' => 10,
        'source' => 'excel_import',
    ]);

    ExtraCode::query()->create([
        'id' => (string) Str::uuid(),
        'item_id' => $item->id,
        'code' => 'ECO-123',
        'source' => 'excel_import',
    ]);

    $record = [
        'product_url' => 'https://example.com/acura/eco-123',
        'brand_page_url' => 'https://www.ecotradegroup.com/en/carbrand/acura',
        'brand_slug' => 'acura',
        'brand' => 'acura',
        'serial_code' => 'ECO 123',
        'product_name' => 'ECO 123',
        'main_image_url' => 'https://images.example.com/eco-123.png',
        'image_urls' => ['https://images.example.com/eco-123.png'],
    ];

    $result = app(EcotradeProductImageCandidateResolver::class)->resolve([$record]);

    expect($result['candidates'])->toHaveCount(1)
        ->and($result['candidates'][0]->item->is($item))->toBeTrue();
});

test('an extra code shared by different primary serials is reported as ambiguous', function () {
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'ACURA',
        'excel_sheet_name' => 'ACURA',
        'region' => 'Japanese',
    ]);

    foreach (['PRIMARY-ONE', 'PRIMARY-TWO'] as $serial) {
        $item = Item::query()->create([
            'id' => (string) Str::uuid(),
            'car_group_id' => $group->id,
            'model' => $serial,
            'serial_code' => $serial,
            'weight_kg' => 1.2,
            'pt_ppm' => 100,
            'pd_ppm' => 200,
            'rh_ppm' => 10,
            'source' => 'excel_import',
        ]);

        ExtraCode::query()->create([
            'id' => (string) Str::uuid(),
            'item_id' => $item->id,
            'code' => 'SHARED-999',
            'source' => 'excel_import',
        ]);
    }

    $record = [
        'product_url' => 'https://example.com/acura/shared-999',
        'brand_page_url' => 'https://www.ecotradegroup.com/en/carbrand/acura',
        'brand_slug' => 'acura',
        'brand' => 'acura',
        'serial_code' => 'SHARED 999',
        'product_name' => 'SHARED 999',
        'main_image_url' => 'https://images.example.com/shared-999.png',
        'image_urls' => ['https://images.example.com/shared-999.png'],
    ];

    $result = app(EcotradeProductImageCandidateResolver::class)->resolve([$record]);

    expect($result['candidates'])->toHaveCount(0)
        ->and($result['summary']['families_ambiguous'])->toBe(1);
});
