<?php

namespace App\Http\Resources\API;

use App\Models\CarGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CarGroup */
class CarGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $imageUrl = $this->resource->getFirstMediaUrl('logo')
            ?: $this->resource->getFirstMediaUrl('images');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'region' => $this->region,
            'parent_id' => $this->parent_id,
            'image_url' => filled($imageUrl) ? $imageUrl : null,
        ];
    }
}
