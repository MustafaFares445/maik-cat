<?php

namespace App\Http\Resources\API;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Item */
class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $imageUrl = null;
        $imageThumbUrl = null;
        $imageDetailUrl = null;

        if (method_exists($this->resource, 'getFirstMediaUrl')) {
            $imageUrl = $this->resource->getFirstMediaUrl('images', 'card')
                ?: $this->resource->getFirstMediaUrl('images');

            $imageThumbUrl = $this->resource->getFirstMediaUrl('images', 'thumb')
                ?: $imageUrl;

            $imageDetailUrl = $this->resource->getFirstMediaUrl('images', 'detail')
                ?: $imageUrl;
        }

        $ptPrice = config('metals.pt_price', 0);
        $pdPrice = config('metals.pd_price', 0);
        $rhPrice = config('metals.rh_price', 0);

        $price = ($this->weight_kg / 1000) * (
            ($this->pt_ppm * $ptPrice) +
            ($this->pd_ppm * $pdPrice) +
            ($this->rh_ppm * $rhPrice)
        );

        return [
            'id' => $this->id,
            'model' => $this->model,
            'serial_code' => $this->serial_code,
            'weight_kg' => $this->weight_kg,
            'pt_ppm' => $this->pt_ppm,
            'pd_ppm' => $this->pd_ppm,
            'rh_ppm' => $this->rh_ppm,
            'shape_code' => $this->shape_code,
            'details' => $this->details,
            'car_group' => CarGroupResource::make($this->whenLoaded('carGroup')),
            'extra_codes' => $this->whenLoaded('extraCodes', fn () => $this->extraCodes->pluck('code')->values()),
            'image_url' => $imageUrl,
            'image_thumb_url' => $imageThumbUrl,
            'image_detail_url' => $imageDetailUrl,
            'saved_item' => (bool) ($this->saved_item ?? false),
            'price' => round($price, 2),
        ];
    }
}
