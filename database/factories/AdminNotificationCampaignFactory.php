<?php

namespace Database\Factories;

use App\Models\AdminNotificationCampaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminNotificationCampaign>
 */
class AdminNotificationCampaignFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sent_by' => User::factory(),
            'audience_mode' => fake()->randomElement(['specific', 'audience', 'all']),
            'audience_id' => null,
            'type' => 'generale_notification',
            'title_en' => fake()->sentence(4),
            'body_en' => fake()->sentence(12),
            'title_ar' => null,
            'body_ar' => null,
            'title_hu' => null,
            'body_hu' => null,
            'payload' => [],
            'total_recipients' => 0,
            'delivered_count' => 0,
            'failed_count' => 0,
            'status' => 'sent',
            'sent_at' => now(),
        ];
    }
}
