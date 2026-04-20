<?php

namespace Database\Seeders;

use App\Models\AppVersion;
use Illuminate\Database\Seeder;

class AppVersionSeeder extends Seeder
{
    public function run(): void
    {
        $policies = [
            [
                'platform' => 'android',
                'latest_version' => '1.0.0',
                'minimum_version' => '1.0.0',
                'store_id' => 'com.example.app',
                'release_notes' => 'Initial release.',
            ],
            [
                'platform' => 'ios',
                'latest_version' => '1.0.0',
                'minimum_version' => '1.0.0',
                'store_id' => '123456789',
                'release_notes' => 'Initial release.',
            ],
        ];

        foreach ($policies as $policy) {
            AppVersion::query()->updateOrCreate(
                ['platform' => $policy['platform']],
                [
                    'latest_version' => $policy['latest_version'],
                    'minimum_version' => $policy['minimum_version'],
                    'store_id' => $policy['store_id'],
                    'release_notes' => $policy['release_notes'],
                ]
            );
        }
    }
}
