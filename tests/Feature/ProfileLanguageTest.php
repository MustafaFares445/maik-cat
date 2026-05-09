<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\patchJson;

uses(RefreshDatabase::class);

test('profile update accepts preferred language', function () {
    $user = User::factory()->create([
        'preferred_language' => 'en',
    ]);

    Sanctum::actingAs($user);

    $response = patchJson('/api/profile', [
        'name' => 'Updated Name',
        'email' => $user->email,
        'preferred_language' => 'ar',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.preferredLanguage', 'ar');

    expect($user->fresh()->preferred_language)->toBe('ar');
});

test('profile update rejects invalid preferred language', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $response = patchJson('/api/profile', [
        'name' => $user->name,
        'email' => $user->email,
        'preferred_language' => 'de',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['preferredLanguage']);
});
