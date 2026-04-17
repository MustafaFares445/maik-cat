<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;

uses(RefreshDatabase::class);

test('notifications endpoint returns authenticated user notifications', function () {
    $user = User::factory()->create();

    DatabaseNotification::query()->create([
        'id' => (string) str()->uuid(),
        'type' => 'App\\Notifications\\ExampleNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => json_encode(['title' => 'New price update', 'body' => 'Platinum moved up'], JSON_THROW_ON_ERROR),
        'read_at' => null,
    ]);

    Sanctum::actingAs($user);

    $response = getJson('/api/notifications');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('unreadCount', 1);
});

test('notifications endpoint marks all unread notifications as read', function () {
    $user = User::factory()->create();

    DatabaseNotification::query()->create([
        'id' => (string) str()->uuid(),
        'type' => 'App\\Notifications\\ExampleNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => json_encode(['title' => 'New price update'], JSON_THROW_ON_ERROR),
        'read_at' => null,
    ]);

    Sanctum::actingAs($user);

    $response = patchJson('/api/notifications/read-all');

    $response->assertOk();
    $response->assertJsonPath('unreadCount', 0);
    expect(DatabaseNotification::query()
        ->where('notifiable_type', User::class)
        ->where('notifiable_id', $user->id)
        ->whereNull('read_at')
        ->exists())->toBeFalse();
});

test('single notification endpoint marks a notification as read', function () {
    $user = User::factory()->create();

    $notification = DatabaseNotification::query()->create([
        'id' => (string) str()->uuid(),
        'type' => 'App\\Notifications\\ExampleNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => json_encode(['title' => 'New price update'], JSON_THROW_ON_ERROR),
        'read_at' => null,
    ]);

    Sanctum::actingAs($user);

    $response = patchJson("/api/notifications/{$notification->id}/read");

    $response->assertOk();
    expect(DatabaseNotification::query()->findOrFail($notification->id)->read_at)->not->toBeNull();
});
