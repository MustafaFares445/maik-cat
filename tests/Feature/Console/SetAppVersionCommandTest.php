<?php

use App\Models\AppVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

test('app version command creates a policy', function () {
    artisan('app:version', [
        'platform' => 'android',
        '--latest' => '2.5.0',
        '--minimum' => '2.0.0',
        '--store' => 'com.example.app',
        '--notes' => 'Stability update',
    ])
        ->assertExitCode(0);

    $policy = AppVersion::query()->where('platform', 'android')->firstOrFail();

    expect($policy->latest_version)->toBe('2.5.0')
        ->and($policy->minimum_version)->toBe('2.0.0')
        ->and($policy->store_id)->toBe('com.example.app')
        ->and($policy->release_notes)->toBe('Stability update');
});

test('app version command updates an existing policy', function () {
    AppVersion::factory()->create([
        'platform' => 'android',
        'latest_version' => '2.5.0',
        'minimum_version' => '2.0.0',
        'store_id' => 'com.example.app',
        'release_notes' => 'Old notes',
    ]);

    artisan('app:version', [
        'platform' => 'android',
        '--latest' => '2.6.0',
        '--minimum' => '2.1.0',
        '--store' => 'com.example.app.v2',
        '--notes' => 'Updated release notes',
    ])
        ->assertExitCode(0);

    $policy = AppVersion::query()->where('platform', 'android')->firstOrFail();

    expect($policy->latest_version)->toBe('2.6.0')
        ->and($policy->minimum_version)->toBe('2.1.0')
        ->and($policy->store_id)->toBe('com.example.app.v2')
        ->and($policy->release_notes)->toBe('Updated release notes');
});

test('app version command rejects invalid version ranges', function () {
    artisan('app:version', [
        'platform' => 'ios',
        '--latest' => '2.0.0',
        '--minimum' => '2.5.0',
        '--store' => '123456789',
    ])
        ->assertExitCode(1);

    expect(AppVersion::query()->count())->toBe(0);
});
