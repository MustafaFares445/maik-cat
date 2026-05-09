<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class AdminCampaignNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $title,
        private readonly string $body,
        private string $type,
        private readonly array $data = [],
    ) {
        $this->type = NotificationType::normalize($this->type);
    }

    public function via(object $notifiable): array
    {
        return ['database', 'fcm'];
    }

    public function toDatabase(object $notifiable): array
    {
        $iconUrl = NotificationType::iconUrl($this->type);

        return [
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'icon_url' => $iconUrl,
            'image_url' => $iconUrl,
            'data' => $this->data,
            'sent_at' => now()->toIso8601String(),
        ];
    }

    public function toFcm(object $notifiable): array
    {
        $iconUrl = NotificationType::iconUrl($this->type);
        $plainTitle = $this->toPlainText($this->title);
        $plainBody = $this->toPlainText($this->body);

        return [
            'title' => $plainTitle,
            'body' => $plainBody,
            'iconUrl' => $iconUrl,
            'imageUrl' => $iconUrl,
            'data' => array_merge($this->data, [
                'type' => $this->type,
                'title_html' => $this->title,
                'body_html' => $this->body,
                'iconUrl' => $iconUrl,
                'imageUrl' => $iconUrl,
            ]),
        ];
    }

    private function toPlainText(string $value): string
    {
        $decoded = html_entity_decode(
            strip_tags(str_replace('&nbsp;', ' ', $value)),
            ENT_QUOTES | ENT_HTML5,
        );

        return Str::of($decoded)
            ->squish()
            ->toString();
    }
}
