<?php

namespace Database\Factories;

use App\Enums\AppPlatform;
use App\Models\AppVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppVersion>
 */
class AppVersionFactory extends Factory
{
    protected $model = AppVersion::class;

    public function definition(): array
    {
        $platform = fake()->randomElement([AppPlatform::ANDROID, AppPlatform::IOS]);

        return [
            'platform' => $platform,
            'latest_version' => '2.5.0',
            'minimum_version' => '2.0.0',
            'store_id' => $platform === AppPlatform::ANDROID ? 'com.example.app' : '123456789',
            'release_notes' => fake()->sentence(),
        ];
    }
}
