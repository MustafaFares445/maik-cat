<?php

namespace Database\Factories;

use App\Models\AdminNotificationCampaign;
use App\Models\AdminNotificationCampaignRecipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminNotificationCampaignRecipient>
 */
class AdminNotificationCampaignRecipientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => AdminNotificationCampaign::factory(),
            'user_id' => User::factory(),
            'preferred_language' => fake()->randomElement(['en', 'ar', 'hu']),
            'language_used' => 'en',
            'notification_id' => null,
            'delivery_status' => 'sent',
            'fcm_message_id' => null,
            'failure_reason' => null,
            'sent_at' => now(),
        ];
    }
}
