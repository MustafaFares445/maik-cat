<?php

namespace Database\Factories;

use App\Models\CarGroup;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        return [
            'car_group_id' => CarGroup::factory(),
            'model' => strtoupper(fake()->bothify('MODEL-###')),
            'serial_code' => fake()->bothify('####-####'),
            'weight_kg' => fake()->randomFloat(3, 0.3, 4.8),
            'pt_ppm' => fake()->randomFloat(4, 10, 2500),
            'pd_ppm' => fake()->randomFloat(4, 10, 1800),
            'rh_ppm' => fake()->randomFloat(4, 1, 350),
            'shape_code' => strtoupper(fake()->bothify('??')),
            'details' => fake()->sentence(),
        ];
    }
}
