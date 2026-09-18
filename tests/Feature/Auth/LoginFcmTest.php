<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

test('first mobile login binds the account to the provided app installation', function (): void {
    $user = User::factory()->create([
        'email' => 'customer@example.com',
        'password' => 'secret123',
        'fcm_token' => null,
        'authorized_device_token' => null,
        'device_bound_at' => null,
    ]);

    $response = postJson('/api/auth/login', [
        'email' => 'customer@example.com',
        'password' => 'secret123',
        'fcmToken' => 'installation-token-a',
    ]);

    $response->assertOk();
    expect($response->json('token'))->not->toBeNull();

    $user->refresh();

    expect($user->fcm_token)->toBe('installation-token-a')
        ->and($user->authorized_device_token)->toBe('installation-token-a')
        ->and($user->device_bound_at)->not->toBeNull()
        ->and($user->hasAuthorizedDevice())->toBeTrue();
});

test('logout keeps the authorized device binding and the same installation can login again', function (): void {
    $user = User::factory()->create([
        'email' => 'returning@example.com',
        'password' => 'secret123',
    ]);

    $firstLogin = postJson('/api/auth/login', [
        'email' => 'returning@example.com',
        'password' => 'secret123',
        'fcm_token' => 'installation-token-a',
    ])->assertOk();

    $boundAt = $user->fresh()->device_bound_at?->toISOString();

    postJson('/api/auth/logout', [], [
        'Authorization' => 'Bearer '.$firstLogin->json('token'),
    ])->assertOk();

    $user->refresh();

    expect($user->tokens()->count())->toBe(0)
        ->and($user->authorized_device_token)->toBe('installation-token-a')
        ->and($user->device_bound_at?->toISOString())->toBe($boundAt);

    postJson('/api/auth/login', [
        'email' => 'returning@example.com',
        'password' => 'secret123',
        'fcm_token' => 'installation-token-a',
    ])->assertOk();

    expect($user->fresh()->authorized_device_token)->toBe('installation-token-a')
        ->and($user->fresh()->device_bound_at?->toISOString())->toBe($boundAt);
});

test('a different installation is rejected even with the correct password and cannot revoke the valid session', function (): void {
    $user = User::factory()->create([
        'email' => 'single-device@example.com',
        'password' => 'secret123',
    ]);

    $firstLogin = postJson('/api/auth/login', [
        'email' => 'single-device@example.com',
        'password' => 'secret123',
        'fcm_token' => 'installation-token-a',
    ])->assertOk();

    $validTokenId = $user->fresh()->tokens()->firstOrFail()->getKey();

    $response = postJson('/api/auth/login', [
        'email' => 'single-device@example.com',
        'password' => 'secret123',
        'fcm_token' => 'installation-token-b',
    ]);

    $response->assertForbidden()
        ->assertJsonPath('code', 'DEVICE_NOT_AUTHORIZED');

    $user->refresh();

    expect($user->authorized_device_token)->toBe('installation-token-a')
        ->and($user->fcm_token)->toBe('installation-token-a')
        ->and($user->tokens()->count())->toBe(1)
        ->and($user->tokens()->whereKey($validTokenId)->exists())->toBeTrue()
        ->and($firstLogin->json('token'))->not->toBeNull();
});

test('mobile login requires the existing installation identifier', function (): void {
    User::factory()->create([
        'email' => 'device-required@example.com',
        'password' => 'secret123',
    ]);

    postJson('/api/auth/login', [
        'email' => 'device-required@example.com',
        'password' => 'secret123',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['fcmToken']);
});

test('a new login from the authorized installation revokes every previous api token for the account', function (): void {
    $user = User::factory()->create([
        'email' => 'one-session@example.com',
        'password' => 'secret123',
        'authorized_device_token' => 'installation-token-a',
        'device_bound_at' => now()->subDay(),
        'fcm_token' => 'installation-token-a',
    ]);

    $oldToken = $user->createToken('old-mobile-session');
    $oldTokenId = $oldToken->accessToken->getKey();

    postJson('/api/auth/login', [
        'email' => 'one-session@example.com',
        'password' => 'secret123',
        'fcm_token' => 'installation-token-a',
    ])->assertOk();

    expect($user->tokens()->count())->toBe(1)
        ->and($user->tokens()->whereKey($oldTokenId)->exists())->toBeFalse()
        ->and($user->tokens()->first()?->name)->toBe('mobile-api-token');
});

test('resetting the authorized device revokes sessions and lets the next installation claim the account', function (): void {
    $user = User::factory()->create([
        'email' => 'reset-device@example.com',
        'password' => 'secret123',
        'authorized_device_token' => 'installation-token-a',
        'device_bound_at' => now()->subDay(),
        'fcm_token' => 'installation-token-a',
    ]);
    $user->createToken('mobile-api-token');

    $user->resetAuthorizedDevice();
    $user->refresh();

    expect($user->authorized_device_token)->toBeNull()
        ->and($user->device_bound_at)->toBeNull()
        ->and($user->fcm_token)->toBeNull()
        ->and($user->tokens()->count())->toBe(0);

    postJson('/api/auth/login', [
        'email' => 'reset-device@example.com',
        'password' => 'secret123',
        'fcm_token' => 'installation-token-b',
    ])->assertOk();

    expect($user->fresh()->authorized_device_token)->toBe('installation-token-b')
        ->and($user->fresh()->fcm_token)->toBe('installation-token-b');
});

test('inactive users cannot login', function (): void {
    User::factory()->create([
        'email' => 'inactive@example.com',
        'password' => 'secret123',
        'is_active' => false,
    ]);

    $response = postJson('/api/auth/login', [
        'email' => 'inactive@example.com',
        'password' => 'secret123',
        'fcm_token' => 'installation-token-a',
    ]);

    $response->assertForbidden();
    $response->assertJsonPath('message', 'Your account is currently inactive. Please contact support.');
});
