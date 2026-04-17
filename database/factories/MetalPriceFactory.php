<?php

namespace Database\Factories;

use App\Models\MetalPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetalPrice>
 */
class MetalPriceFactory extends Factory
{
    protected $model = MetalPrice::class;

    public function definition(): array
    {
        return [
            'pt_usd_per_oz' => fake()->randomFloat(4, 600, 1800),
            'pd_usd_per_oz' => fake()->randomFloat(4, 550, 1700),
            'rh_usd_per_oz' => fake()->randomFloat(4, 1200, 8000),
            'source' => 'factory',
            'fetched_at' => fake()->dateTimeBetween('-20 days', 'now'),
        ];
    }
}
