<?php

use App\Enums\AppPlatform;
use App\Models\AppVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

test('version check endpoint returns force update state', function () {
    AppVersion::factory()->create([
        'platform' => AppPlatform::ANDROID,
        'latest_version' => '2.5.0',
        'minimum_version' => '2.0.0',
        'store_id' => 'com.example.app',
        'release_notes' => 'Stability update',
    ]);

    $response = postJson('/api/v1/app/version-check', [
        'platform' => 'android',
        'version' => '1.9.9',
    ]);

    $response->assertOk();
    $response->assertJsonPath('platform', 'android');
    $response->assertJsonPath('currentVersion', '1.9.9');
    $response->assertJsonPath('latestVersion', '2.5.0');
    $response->assertJsonPath('minimumVersion', '2.0.0');
    $response->assertJsonPath('updateRequired', true);
    $response->assertJsonPath('updateAvailable', true);
    $response->assertJsonPath('storeUrl', 'https://play.google.com/store/apps/details?id=com.example.app');
    $response->assertJsonPath('releaseNotes', 'Stability update');
});

test('version check endpoint returns soft update state', function () {
    AppVersion::factory()->create([
        'platform' => AppPlatform::ANDROID,
        'latest_version' => '2.5.0',
        'minimum_version' => '2.0.0',
        'store_id' => 'com.example.app',
        'release_notes' => 'Bug fixes and improvements',
    ]);

    $response = postJson('/api/v1/app/version-check', [
        'platform' => 'android',
        'version' => '2.3.0',
    ]);

    $response->assertOk();
    $response->assertJsonPath('updateRequired', false);
    $response->assertJsonPath('updateAvailable', true);
});

test('version check endpoint returns no update state', function () {
    AppVersion::factory()->create([
        'platform' => AppPlatform::IOS,
        'latest_version' => '2.5.0',
        'minimum_version' => '2.0.0',
        'store_id' => '123456789',
        'release_notes' => null,
    ]);

    $response = getJson('/api/app-version?platform=ios&version=2.5.0');

    $response->assertOk();
    $response->assertJsonPath('platform', 'ios');
    $response->assertJsonPath('updateRequired', false);
    $response->assertJsonPath('updateAvailable', false);
    $response->assertJsonPath('storeUrl', 'https://apps.apple.com/app/id123456789');
    $response->assertJsonPath('releaseNotes', null);
});

test('version check endpoint returns not found when policy is missing', function () {
    $response = postJson('/api/v1/app/version-check', [
        'platform' => 'android',
        'version' => '2.3.0',
    ]);

    $response->assertNotFound();
    $response->assertJsonPath('message', 'No version config found for platform: android');
});

test('version check endpoint validates payload', function () {
    $response = postJson('/api/v1/app/version-check', [
        'platform' => 'windows',
        'version' => '2.3',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['platform', 'version']);
});
