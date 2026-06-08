<?php

namespace App\Services\Ecotrade;

use App\Data\EcotradeProductData;
use App\Models\CarGroup;
use App\Models\ImportBatch;
use App\Models\Item;

class EcotradeProductImporter
{
    public function import(EcotradeProductData $data, CarGroup $brand, ImportBatch $batch): Item
    {
        $payload = [
            'car_group_id' => $brand->id,
            'model' => $data->productName,
            'serial_code' => $data->serialCode,
            'normalized_serial' => Item::normalizeSerialValue($data->serialCode),
            'weight_kg' => null,
            'pt_ppm' => null,
            'pd_ppm' => null,
            'rh_ppm' => null,
            'shape_code' => null,
            'details' => json_encode(
                $data->detailsPayload(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            'source' => 'ecotrade',
            'source_url' => $data->productUrl,
            'source_hash' => $data->sourceHash,
        ];

        $item = Item::query()
            ->where('source', 'ecotrade')
            ->where('source_url', $data->productUrl)
            ->first();

        if (! $item) {
            $item = Item::query()
                ->where('source', 'ecotrade')
                ->where('source_hash', $data->sourceHash)
                ->first();
        }

        if ($item) {
            $item->fill($payload);
            $item->save();
        } else {
            $item = Item::query()->create($payload);
        }

        return $item->refresh();
    }
}
