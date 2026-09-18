<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemFilterMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ItemFilterMapping> */
class ItemFilterMappingFactory extends Factory
{
    protected $model = ItemFilterMapping::class;

    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'filter_item_id' => null,
            'filter_serial' => null,
            'detection_method' => 'manual',
            'confidence' => 'low',
            'status' => ItemFilterMapping::STATUS_NEEDS_REVIEW,
            'filter_weight_override' => null,
            'evidence' => [],
        ];
    }
}
