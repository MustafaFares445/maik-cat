<?php

use App\Enums\NotificationType;
use App\Models\NotificationAudience;
use App\Models\User;
use App\Services\AdminNotificationCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function seedRole(string $name): Role
{
    return Role::query()->firstOrCreate([
        'name' => $name,
        'guard_name' => 'web',
    ]);
}

function fakeMessaging(): void
{
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')->andReturnUsing(
        fn () => 'projects/test/messages/'.str()->uuid()->toString()
    );

    app()->instance(Messaging::class, $messaging);
}

test('service sends campaign to specific users with locale fallback', function () {
    fakeMessaging();
    seedRole('admin');
    seedRole('app_user');

    $sender = User::factory()->create()->assignRole('admin');
    $arabicUser = User::factory()->create(['preferred_language' => 'ar', 'is_active' => true])->assignRole('app_user');
    $hungarianUser = User::factory()->create(['preferred_language' => 'hu', 'is_active' => true])->assignRole('app_user');

    $campaign = app(AdminNotificationCampaignService::class)->sendCampaign($sender, [
        'audience_mode' => 'specific',
        'user_ids' => [$arabicUser->id, $hungarianUser->id],
        'type' => NotificationType::GENERALE_NOTIFICATION,
        'title_en' => 'English title',
        'body_en' => 'English body',
        'title_ar' => 'Ø¹Ù†ÙˆØ§Ù† Ø¹Ø±Ø¨ÙŠ',
        'body_ar' => 'Ù…Ø­ØªÙˆÙ‰ Ø¹Ø±Ø¨ÙŠ',
        'title_hu' => null,
        'body_hu' => null,
    ]);

    expect($campaign->total_recipients)->toBe(2)
        ->and($campaign->delivered_count)->toBe(2)
        ->and($campaign->failed_count)->toBe(0)
        ->and($campaign->status)->toBe('sent');

    $arabicRecipient = $campaign->recipients->firstWhere('user_id', $arabicUser->id);
    $hungarianRecipient = $campaign->recipients->firstWhere('user_id', $hungarianUser->id);

    expect($arabicRecipient)->not->toBeNull()
        ->and($arabicRecipient->language_used)->toBe('ar');
    expect($hungarianRecipient)->not->toBeNull()
        ->and($hungarianRecipient->language_used)->toBe('en');
});

test('service sends campaign to active users inside an audience group', function () {
    fakeMessaging();
    seedRole('admin');
    seedRole('app_user');

    $sender = User::factory()->create()->assignRole('admin');
    $activeUser = User::factory()->create(['is_active' => true])->assignRole('app_user');
    $inactiveUser = User::factory()->create(['is_active' => false])->assignRole('app_user');

    $audience = NotificationAudience::factory()->create();
    $audience->users()->attach([$activeUser->id, $inactiveUser->id]);

    $campaign = app(AdminNotificationCampaignService::class)->sendCampaign($sender, [
        'audience_mode' => 'audience',
        'audience_id' => $audience->id,
        'type' => NotificationType::GENERALE_NOTIFICATION,
        'title_en' => 'Audience title',
        'body_en' => 'Audience body',
        'title_ar' => null,
        'body_ar' => null,
        'title_hu' => null,
        'body_hu' => null,
    ]);

    expect($campaign->total_recipients)->toBe(1)
        ->and($campaign->recipients->pluck('user_id')->all())->toBe([$activeUser->id]);
});

test('service sends campaign to all active app users only', function () {
    fakeMessaging();
    seedRole('admin');
    seedRole('app_user');

    $sender = User::factory()->create()->assignRole('admin');
    $activeOne = User::factory()->create(['is_active' => true])->assignRole('app_user');
    $activeTwo = User::factory()->create(['is_active' => true])->assignRole('app_user');
    User::factory()->create(['is_active' => false])->assignRole('app_user');
    User::factory()->create(['is_active' => true])->assignRole('admin');

    $campaign = app(AdminNotificationCampaignService::class)->sendCampaign($sender, [
        'audience_mode' => 'all',
        'type' => NotificationType::GENERALE_NOTIFICATION,
        'title_en' => 'Global title',
        'body_en' => 'Global body',
        'title_ar' => null,
        'body_ar' => null,
        'title_hu' => null,
        'body_hu' => null,
    ]);

    $recipientIds = $campaign->recipients->pluck('user_id')->sort()->values()->all();

    expect($campaign->total_recipients)->toBe(2)
        ->and($recipientIds)->toBe(collect([$activeOne->id, $activeTwo->id])->sort()->values()->all());
});

test('service normalizes label-style notification type values', function () {
    fakeMessaging();
    seedRole('admin');
    seedRole('app_user');

    $sender = User::factory()->create()->assignRole('admin');
    $recipientUser = User::factory()->create(['is_active' => true])->assignRole('app_user');

    $campaign = app(AdminNotificationCampaignService::class)->sendCampaign($sender, [
        'audience_mode' => 'specific',
        'user_ids' => [$recipientUser->id],
        'type' => 'Add New Item',
        'title_en' => 'Inventory update',
        'body_en' => 'A converter item was added.',
    ]);

    $notification = $recipientUser->notifications()->latest()->first();

    expect($campaign->type)->toBe(NotificationType::ADD_NEW_ITEM)
        ->and($notification)->not->toBeNull()
        ->and($notification->data['type'])->toBe(NotificationType::ADD_NEW_ITEM);
});

test('service treats empty rich text locale fields as missing and falls back to english', function () {
    fakeMessaging();
    seedRole('admin');
    seedRole('app_user');

    $sender = User::factory()->create()->assignRole('admin');
    $arabicUser = User::factory()->create(['preferred_language' => 'ar', 'is_active' => true])->assignRole('app_user');

    $campaign = app(AdminNotificationCampaignService::class)->sendCampaign($sender, [
        'audience_mode' => 'specific',
        'user_ids' => [$arabicUser->id],
        'type' => NotificationType::GENERALE_NOTIFICATION,
        'title_en' => '<p><strong>English title</strong></p>',
        'body_en' => '<p>English <span style="color:#ef4444;">body</span></p>',
        'title_ar' => '<p></p>',
        'body_ar' => '<p></p>',
    ]);

    $recipient = $campaign->recipients->firstWhere('user_id', $arabicUser->id);
    $notification = $arabicUser->notifications()->latest()->first();

    expect($recipient)->not->toBeNull()
        ->and($recipient->language_used)->toBe('en')
        ->and($notification)->not->toBeNull()
        ->and($notification->data['title'])->toContain('<strong>English title</strong>');
});
