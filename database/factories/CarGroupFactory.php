<?php

namespace Database\Factories;

use App\Models\CarGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarGroup>
 */
class CarGroupFactory extends Factory
{
    protected $model = CarGroup::class;

    public function definition(): array
    {
        $name = strtoupper(fake()->lexify('GROUP ???'));

        return [
            'name' => $name,
            'excel_sheet_name' => $name,
            'region' => fake()->randomElement(['European', 'Asian', 'American']),
            'parent_id' => null,
        ];
    }
}
