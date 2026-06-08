<?php

namespace App\Services\Ecotrade;

use App\Data\EcotradeProductData;
use App\Models\CarGroup;

class EcotradeBrandImporter
{
    /**
     * @var array<string, CarGroup>
     */
    private array $cache = [];

    public function import(EcotradeProductData $data): CarGroup
    {
        if (isset($this->cache[$data->brandSlug])) {
            return $this->cache[$data->brandSlug];
        }

        $sheetName = $this->sheetNameFromBrandName($data->brandName);

        $brand = CarGroup::query()
            ->where('source', 'ecotrade')
            ->where('slug', $data->brandSlug)
            ->first()
            ?? CarGroup::query()
                ->where('excel_sheet_name', $sheetName)
                ->first();

        $attributes = [
            'name' => $data->brandName,
            'slug' => $data->brandSlug,
            'excel_sheet_name' => $sheetName,
            'region' => null,
            'parent_id' => null,
            'source' => 'ecotrade',
            'source_url' => $data->brandPageUrl,
        ];

        if ($brand) {
            $brand->fill($attributes);
            $brand->save();
        } else {
            $brand = CarGroup::query()->create($attributes);
        }

        return $this->cache[$data->brandSlug] = $brand->refresh();
    }

    private function sheetNameFromBrandName(string $brandName): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $brandName) ?: $brandName));
    }
}
