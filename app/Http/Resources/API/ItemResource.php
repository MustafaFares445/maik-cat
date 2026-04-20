<?php

namespace App\Http\Resources\API;

use App\Models\Item;
use App\Http\Resources\API\CarGroupResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Item */
class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $imageUrl = method_exists($this->resource, 'getFirstMediaUrl')
            ? $this->resource->getFirstMediaUrl('images')
            : null;

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
            'extra_codes' => $this->whenLoaded('extraCodes', fn() => $this->extraCodes->pluck('code')->values()),
            'image_url' => $imageUrl,
            'saved_item' => (bool) ($this->saved_item ?? false),
        ];
    }
}
