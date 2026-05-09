<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\PreferredLanguage;
use App\Models\AdminNotificationCampaign;
use App\Models\AdminNotificationCampaignRecipient;
use App\Models\NotificationAudience;
use App\Models\User;
use App\Notifications\AdminCampaignNotification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use RuntimeException;
use Throwable;

class AdminNotificationCampaignService
{
    /**
     * @param  array{
     *     audience_mode: string,
     *     audience_id?: string|null,
     *     user_ids?: array<int, int>|array<int, string>,
     *     type: string,
     *     title_en: string,
     *     body_en: string,
     *     title_ar?: string|null,
     *     body_ar?: string|null,
     *     title_hu?: string|null,
     *     body_hu?: string|null
     * } $data
     */
    public function sendCampaign(User $sender, array $data): AdminNotificationCampaign
    {
        $type = NotificationType::normalize((string) ($data['type'] ?? ''));

        $campaign = AdminNotificationCampaign::query()->create([
            'sent_by' => $sender->getKey(),
            'audience_mode' => $data['audience_mode'],
            'audience_id' => $data['audience_id'] ?? null,
            'type' => $type,
            'title_en' => $data['title_en'],
            'body_en' => $data['body_en'],
            'title_ar' => $this->normalizeNullableString($data['title_ar'] ?? null),
            'body_ar' => $this->normalizeNullableString($data['body_ar'] ?? null),
            'title_hu' => $this->normalizeNullableString($data['title_hu'] ?? null),
            'body_hu' => $this->normalizeNullableString($data['body_hu'] ?? null),
            'payload' => [],
            'status' => 'sending',
        ]);

        $recipients = $this->resolveRecipients($data);
        $totalRecipients = $recipients->count();
        $deliveredCount = 0;
        $failedCount = 0;

        foreach ($recipients as $recipientUser) {
            $preferredLanguage = $recipientUser->preferredLanguageOrDefault();
            [$title, $body, $languageUsed] = $this->resolveLocalizedContent($data, $preferredLanguage);

            $recipient = AdminNotificationCampaignRecipient::query()->create([
                'campaign_id' => $campaign->id,
                'user_id' => $recipientUser->getKey(),
                'preferred_language' => $preferredLanguage,
                'language_used' => $languageUsed,
                'delivery_status' => 'pending',
            ]);

            try {
                $recipientUser->notify(new AdminCampaignNotification(
                    title: $title,
                    body: $body,
                    type: $type,
                    data: [
                        'campaign_id' => $campaign->id,
                        'language_used' => $languageUsed,
                    ],
                ));

                $notificationId = $recipientUser->notifications()
                    ->where('type', AdminCampaignNotification::class)
                    ->latest('created_at')
                    ->value('id');

                $recipient->forceFill([
                    'notification_id' => $notificationId,
                    'delivery_status' => 'sent',
                    'sent_at' => now(),
                ])->save();

                $deliveredCount++;
            } catch (Throwable $exception) {
                $recipient->forceFill([
                    'delivery_status' => 'failed',
                    'failure_reason' => mb_substr($exception->getMessage(), 0, 500),
                    'sent_at' => now(),
                ])->save();

                $failedCount++;
            }
        }

        $campaign->forceFill([
            'total_recipients' => $totalRecipients,
            'delivered_count' => $deliveredCount,
            'failed_count' => $failedCount,
            'status' => 'sent',
            'sent_at' => now(),
        ])->save();

        return $campaign->fresh(['sender', 'audience', 'recipients.user']) ?? $campaign;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return EloquentCollection<int, User>
     */
    private function resolveRecipients(array $data): EloquentCollection
    {
        $audienceMode = $data['audience_mode'] ?? 'specific';

        if ($audienceMode === 'all') {
            return User::query()
                ->role('app_user')
                ->where('is_active', true)
                ->orderBy('id')
                ->get();
        }

        if ($audienceMode === 'audience') {
            $audienceId = $data['audience_id'] ?? null;

            if (! is_string($audienceId) || $audienceId === '') {
                throw new RuntimeException('Audience is required for audience notification mode.');
            }

            /** @var NotificationAudience|null $audience */
            $audience = NotificationAudience::query()->find($audienceId);

            if (! $audience || ! $audience->is_active) {
                throw new RuntimeException('Selected audience is not available.');
            }

            return User::query()
                ->role('app_user')
                ->where('is_active', true)
                ->whereIn('id', $audience->users()->pluck('users.id'))
                ->orderBy('id')
                ->get();
        }

        $specificUserIds = collect($data['user_ids'] ?? [])
            ->filter(static fn ($id): bool => is_numeric($id) || (is_string($id) && $id !== ''))
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($specificUserIds->isEmpty()) {
            throw new RuntimeException('Select at least one target user.');
        }

        return User::query()
            ->role('app_user')
            ->where('is_active', true)
            ->whereIn('id', $specificUserIds)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveLocalizedContent(array $data, string $preferredLanguage): array
    {
        $titleEn = trim((string) ($data['title_en'] ?? ''));
        $bodyEn = trim((string) ($data['body_en'] ?? ''));

        if ($preferredLanguage === PreferredLanguage::AR->value) {
            $title = $this->normalizeNullableString($data['title_ar'] ?? null);
            $body = $this->normalizeNullableString($data['body_ar'] ?? null);

            if ($title !== null && $body !== null) {
                return [$title, $body, PreferredLanguage::AR->value];
            }
        }

        if ($preferredLanguage === PreferredLanguage::HU->value) {
            $title = $this->normalizeNullableString($data['title_hu'] ?? null);
            $body = $this->normalizeNullableString($data['body_hu'] ?? null);

            if ($title !== null && $body !== null) {
                return [$title, $body, PreferredLanguage::HU->value];
            }
        }

        return [$titleEn, $bodyEn, PreferredLanguage::EN->value];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        $plainText = trim(
            html_entity_decode(
                strip_tags(str_replace('&nbsp;', ' ', $trimmed)),
                ENT_QUOTES | ENT_HTML5,
            ),
        );

        return $plainText === '' ? null : $trimmed;
    }
}
