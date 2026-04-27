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
