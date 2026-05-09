<?php

namespace Database\Factories;

use App\Models\NotificationAudience;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationAudience>
 */
class NotificationAudienceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'is_active' => true,
            'created_by' => null,
        ];
    }
}
