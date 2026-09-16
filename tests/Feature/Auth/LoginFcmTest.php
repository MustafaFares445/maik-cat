<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

test('login stores fcm token when provided', function () {
    $user = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'secret123',
        'fcm_token' => null,
    ]);

    $response = postJson('/api/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'secret123',
        'fcmToken' => 'test-fcm-token-123',
    ]);

    $response->assertOk();
    expect($response->json('token'))->not->toBeNull();
    expect($user->fresh()->fcm_token)->toBe('test-fcm-token-123');
});

test('login works without fcm token and keeps existing token untouched', function () {
    $user = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'secret123',
        'fcm_token' => 'existing-token',
    ]);

    $response = postJson('/api/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'secret123',
    ]);

    $response->assertOk();
    expect($user->fresh()->fcm_token)->toBe('existing-token');
});

test('a new mobile login revokes every previous api token for the account', function () {
    $user = User::factory()->create([
        'email' => 'single-device@example.com',
        'password' => 'secret123',
    ]);
    $oldToken = $user->createToken('old-mobile-device');
    $oldTokenId = $oldToken->accessToken->getKey();

    $response = postJson('/api/auth/login', [
        'email' => 'single-device@example.com',
        'password' => 'secret123',
    ]);

    $response->assertOk();
    expect($user->tokens()->count())->toBe(1)
        ->and($user->tokens()->whereKey($oldTokenId)->exists())->toBeFalse()
        ->and($user->tokens()->first()?->name)->toBe('mobile-api-token');
});

test('inactive users cannot login', function () {
    User::factory()->create([
        'email' => 'inactive@example.com',
        'password' => 'secret123',
        'is_active' => false,
    ]);

    $response = postJson('/api/auth/login', [
        'email' => 'inactive@example.com',
        'password' => 'secret123',
    ]);

    $response->assertForbidden();
    $response->assertJsonPath('message', 'Your account is currently inactive. Please contact support.');
});
