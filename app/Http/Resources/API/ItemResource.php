<?php

namespace App\Http\Resources\API;

use App\Models\Item;
use App\Services\Mobile\ItemPriceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Item */
class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currency = strtoupper((string) $request->query('currency', 'USD'));
        $price = app(ItemPriceService::class)->priceFor($this->resource, $currency);

        return [
            'id' => $this->id,
            'model' => $this->model,
            'serial_code' => $this->normalized_serial,
            'weight_kg' => $this->weight_kg,
            'pt_ppm' => $this->pt_ppm,
            'pd_ppm' => $this->pd_ppm,
            'rh_ppm' => $this->rh_ppm,
            'shape_code' => $this->shape_code,
            'details' => $this->details,
            'car_group' => CarGroupResource::make($this->whenLoaded('carGroup')),
            'extra_codes' => $this->whenLoaded('extraCodes', fn () => $this->extraCodes->pluck('code')->values()),
            'image_url' => $this->image_url,
            'image_thumb_url' => $this->image_thumb_url,
            'image_detail_url' => $this->image_detail_url,
            'saved_item' => (bool) ($this->saved_item ?? false),
            'price' => round($price, 2),
        ];
    }
}
