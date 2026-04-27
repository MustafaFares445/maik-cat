<?php

use App\Enums\NotificationType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Kreait\Firebase\Contract\Messaging;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

test('test fcm endpoint requires authentication', function () {
    $response = postJson('/api/notifications/test-fcm', [
        'title' => 'Test title',
        'body' => 'Test body',
    ]);

    $response->assertUnauthorized();
});

test('test fcm endpoint returns validation error when user has no fcm token', function () {
    $user = User::factory()->create([
        'fcm_token' => null,
    ]);

    Sanctum::actingAs($user);

    $response = postJson('/api/notifications/test-fcm', [
        'title' => 'Test title',
        'body' => 'Test body',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonPath('message', 'User does not have an FCM token.');
});

test('test fcm endpoint sends push through firebase and stores database notification', function () {
    $sentPayload = null;
    $messaging = Mockery::mock(Messaging::class);
    $messaging
        ->shouldReceive('send')
        ->once()
        ->with(Mockery::on(function (mixed $message) use (&$sentPayload): bool {
            $sentPayload = $message instanceof JsonSerializable
                ? $message->jsonSerialize()
                : $message;

            return true;
        }))
        ->andReturn([
            'name' => 'projects/test/messages/1',
        ]);
    app()->instance(Messaging::class, $messaging);

    $user = User::factory()->create([
        'fcm_token' => 'test-user-fcm-token',
    ]);

    Sanctum::actingAs($user);

    $response = postJson('/api/notifications/test-fcm', [
        'title' => 'Metal prices update',
        'body' => 'Platinum moved by +1.2%',
        'data' => [
            'source' => 'manual_test',
            'priority' => 1,
        ],
    ]);

    $response->assertOk();
    $response->assertJsonPath('message', 'Test notification sent successfully.');

    $expectedIconUrl = NotificationType::iconUrl(NotificationType::GENERALE_NOTIFICATION);

    $notification = DatabaseNotification::query()
        ->where('notifiable_type', User::class)
        ->where('notifiable_id', $user->id)
        ->latest()
        ->first();

    expect($notification)->not->toBeNull();
    expect($notification->data['type'])->toBe(NotificationType::GENERALE_NOTIFICATION);
    expect($notification->data['title'])->toBe('Metal prices update');
    expect($notification->data['body'])->toBe('Platinum moved by +1.2%');
    expect($notification->data['icon_url'])->toBe($expectedIconUrl);
    expect($notification->data['image_url'])->toBe($expectedIconUrl);
    expect($notification->data['data'])->toBe([
        'source' => 'manual_test',
        'priority' => 1,
    ]);
    expect($sentPayload['notification']['image'])->toBe($expectedIconUrl);
    expect($sentPayload['data']['type'])->toBe(NotificationType::GENERALE_NOTIFICATION);
    expect($sentPayload['data']['iconUrl'])->toBe($expectedIconUrl);
    expect($sentPayload['data']['imageUrl'])->toBe($expectedIconUrl);
});

test('test fcm endpoint accepts explicit allowed notification type', function () {
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')->once()->andReturn([
        'name' => 'projects/test/messages/2',
    ]);
    app()->instance(Messaging::class, $messaging);

    $user = User::factory()->create([
        'fcm_token' => 'test-user-fcm-token',
    ]);

    Sanctum::actingAs($user);

    $response = postJson('/api/notifications/test-fcm', [
        'title' => 'New item arrived',
        'body' => 'A new converter item was added.',
        'type' => NotificationType::ADD_NEW_ITEM,
    ]);

    $response->assertOk();

    $notification = DatabaseNotification::query()
        ->where('notifiable_type', User::class)
        ->where('notifiable_id', $user->id)
        ->latest()
        ->first();

    expect($notification)->not->toBeNull();
    expect($notification->data['type'])->toBe(NotificationType::ADD_NEW_ITEM);
    expect($notification->data['icon_url'])->toBe(NotificationType::iconUrl(NotificationType::ADD_NEW_ITEM));
    expect($notification->data['image_url'])->toBe(NotificationType::iconUrl(NotificationType::ADD_NEW_ITEM));
});

test('notification types expose public icon urls', function () {
    foreach (NotificationType::values() as $type) {
        expect(NotificationType::iconUrl($type))
            ->toBe(asset(NotificationType::iconPath($type)))
            ->and(NotificationType::imageUrl($type))
            ->toBe(NotificationType::iconUrl($type))
            ->and(NotificationType::iconPath($type))
            ->toBe("images/notifications/{$type}.svg");
    }
});

test('test fcm endpoint rejects unknown notification type', function () {
    $user = User::factory()->create([
        'fcm_token' => 'test-user-fcm-token',
    ]);

    Sanctum::actingAs($user);

    $response = postJson('/api/notifications/test-fcm', [
        'title' => 'Title',
        'body' => 'Body',
        'type' => 'unknown_notification_type',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['type']);
});
