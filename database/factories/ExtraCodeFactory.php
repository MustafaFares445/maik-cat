<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ExtraCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExtraCode>
 */
class ExtraCodeFactory extends Factory
{
    protected $model = ExtraCode::class;

    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'code' => strtoupper(fake()->bothify('??##??##')),
            'source' => 'factory',
        ];
    }
}

