<?php

use App\Models\CarGroup;
use App\Models\ExtraCode;
use App\Models\Item;
use App\Services\Ecotrade\EcotradeProductImageCandidateResolver;
use App\Services\Ecotrade\EcotradeRecordNormalizer;
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

test('an exact primary serial is preferred over another item using it as an extra code', function () {
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'ACURA',
        'excel_sheet_name' => 'ACURA',
        'region' => 'Japanese',
    ]);

    $exactItem = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'Exact Ecotrade code',
        'serial_code' => 'SHARED-999',
        'weight_kg' => 1.2,
        'pt_ppm' => 100,
        'pd_ppm' => 200,
        'rh_ppm' => 10,
        'source' => 'excel_import',
    ]);

    $aliasItem = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'Alias code',
        'serial_code' => 'PRIMARY-OTHER',
        'weight_kg' => 1.2,
        'pt_ppm' => 100,
        'pd_ppm' => 200,
        'rh_ppm' => 10,
        'source' => 'excel_import',
    ]);

    ExtraCode::query()->create([
        'id' => (string) Str::uuid(),
        'item_id' => $aliasItem->id,
        'code' => 'SHARED 999',
        'source' => 'excel_import',
    ]);

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

    expect($result['candidates'])->toHaveCount(1)
        ->and($result['candidates'][0]->item->is($exactItem))->toBeTrue()
        ->and($result['summary']['families_ambiguous'])->toBe(0);
});

test('the Mini Cooper Ecotrade brand resolves to the workbook BMW group', function () {
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'BMW',
        'excel_sheet_name' => 'BMW',
        'region' => 'German',
    ]);

    $item = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'MINI',
        'serial_code' => '7566814',
        'weight_kg' => 0.84,
        'pt_ppm' => 0,
        'pd_ppm' => 4.124,
        'rh_ppm' => 0,
        'source' => 'excel_import',
    ]);

    $record = [
        'product_url' => 'https://example.com/mini-cooper/7566814',
        'brand_page_url' => 'https://www.ecotradegroup.com/en/carbrand/mini-cooper',
        'brand_slug' => 'mini-cooper',
        'brand' => 'Mini Cooper',
        'serial_code' => '7566814',
        'product_name' => '7566814',
        'main_image_url' => 'https://images.example.com/7566814.png',
        'image_urls' => ['https://images.example.com/7566814.png'],
    ];

    $result = app(EcotradeProductImageCandidateResolver::class)->resolve([$record]);

    expect($result['candidates'])->toHaveCount(1)
        ->and($result['candidates'][0]->item->is($item))->toBeTrue();
});

test('a trailing serial code after the product name resolves as a separate image family', function () {
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'AUDI VW',
        'excel_sheet_name' => 'AUDI VW',
        'region' => 'German',
    ]);

    $items = collect(['036131703C', '036166AB'])->mapWithKeys(function (string $serial) use ($group): array {
        $item = Item::query()->create([
            'id' => (string) Str::uuid(),
            'car_group_id' => $group->id,
            'model' => 'AUDI',
            'serial_code' => $serial,
            'weight_kg' => 1.2,
            'pt_ppm' => 100,
            'pd_ppm' => 200,
            'rh_ppm' => 10,
            'source' => 'excel_import',
        ]);

        return [$serial => $item];
    });

    $record = [
        'product_url' => 'https://example.com/audi/036131703c',
        'brand_page_url' => 'https://www.ecotradegroup.com/en/carbrand/audi',
        'brand_slug' => 'audi',
        'brand' => 'Audi',
        'serial_code' => '036131703C 036166AB',
        'product_name' => '036131703C',
        'main_image_url' => 'https://images.example.com/036131703c.png',
        'image_urls' => ['https://images.example.com/036131703c.png'],
    ];

    $result = app(EcotradeProductImageCandidateResolver::class)->resolve([$record]);

    expect(collect($result['candidates'])->pluck('item.id')->all())
        ->toEqualCanonicalizing($items->pluck('id')->all());
});

test('ordinary spaces inside one serial code do not create partial families', function () {
    $normalizer = app(EcotradeRecordNormalizer::class);
    $product = $normalizer->normalize([
        'product_url' => 'https://example.com/mercedes/kt-1128',
        'brand_page_url' => 'https://www.ecotradegroup.com/en/carbrand/mercedes',
        'brand_slug' => 'mercedes',
        'brand' => 'Mercedes',
        'serial_code' => 'KT 1128',
        'product_name' => 'KT 1128',
        'main_image_url' => 'https://images.example.com/kt-1128.png',
        'image_urls' => ['https://images.example.com/kt-1128.png'],
    ]);

    expect($normalizer->serialFamilies($product))->toBe(['KT1128']);
});

test('the same separated serial codes resolve when product name reverses their order', function () {
    $normalizer = app(EcotradeRecordNormalizer::class);
    $product = $normalizer->normalize([
        'product_url' => 'https://example.com/audi/038253031h',
        'brand_page_url' => 'https://www.ecotradegroup.com/en/carbrand/audi',
        'brand_slug' => 'audi',
        'brand' => 'Audi',
        'serial_code' => '038253031H 038178BA',
        'product_name' => '038178BA 038253031H',
        'main_image_url' => 'https://images.example.com/038253031h.png',
        'image_urls' => ['https://images.example.com/038253031h.png'],
    ]);

    expect($normalizer->serialFamilies($product))->toEqualCanonicalizing([
        '038253031H038178BA',
        '038253031H',
        '038178BA',
    ]);
});

test('one item matched through two serial families produces one image candidate', function () {
    $group = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'AUDI VW',
        'excel_sheet_name' => 'AUDI VW',
        'region' => 'German',
    ]);
    $item = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $group->id,
        'model' => 'AUDI',
        'serial_code' => 'PRIMARY100',
        'weight_kg' => 1.2,
        'pt_ppm' => 100,
        'pd_ppm' => 200,
        'rh_ppm' => 10,
        'source' => 'excel_import',
    ]);
    ExtraCode::query()->create([
        'id' => (string) Str::uuid(),
        'item_id' => $item->id,
        'code' => 'ALIAS200',
        'source' => 'excel_import',
    ]);
    $record = [
        'product_url' => 'https://example.com/audi/primary100',
        'brand_page_url' => 'https://www.ecotradegroup.com/en/carbrand/audi',
        'brand_slug' => 'audi',
        'brand' => 'Audi',
        'serial_code' => 'PRIMARY100 ALIAS200',
        'product_name' => 'PRIMARY100',
        'main_image_url' => 'https://images.example.com/primary100.png',
        'image_urls' => ['https://images.example.com/primary100.png'],
    ];

    $result = app(EcotradeProductImageCandidateResolver::class)->resolve([$record]);

    expect($result['candidates'])->toHaveCount(1)
        ->and($result['candidates'][0]->item->is($item))->toBeTrue();
});

test('an allowlisted item can use a unique image from another car group', function () {
    $itemGroup = CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'BMW',
        'excel_sheet_name' => 'BMW',
        'region' => 'German',
    ]);
    CarGroup::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'AUDI VW',
        'excel_sheet_name' => 'AUDI VW',
        'region' => 'German',
    ]);
    $item = Item::query()->create([
        'id' => (string) Str::uuid(),
        'car_group_id' => $itemGroup->id,
        'model' => 'BMW',
        'serial_code' => 'CROSS100',
        'weight_kg' => 1.2,
        'pt_ppm' => 100,
        'pd_ppm' => 200,
        'rh_ppm' => 10,
        'source' => 'excel_import',
    ]);
    $record = [
        'product_url' => 'https://example.com/audi/cross100',
        'brand_page_url' => 'https://www.ecotradegroup.com/en/carbrand/audi',
        'brand_slug' => 'audi',
        'brand' => 'Audi',
        'serial_code' => 'CROSS100',
        'product_name' => 'CROSS100',
        'main_image_url' => 'https://images.example.com/cross100.png',
        'image_urls' => ['https://images.example.com/cross100.png'],
    ];
    $resolver = app(EcotradeProductImageCandidateResolver::class);

    expect($resolver->resolve([$record])['candidates'])->toHaveCount(0);

    $result = $resolver->resolve([$record], [
        'allowed_item_ids' => [$item->id],
        'allow_cross_group' => true,
    ]);

    expect($result['candidates'])->toHaveCount(1)
        ->and($result['candidates'][0]->item->is($item))->toBeTrue();
});
