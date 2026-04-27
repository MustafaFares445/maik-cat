<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TestFcmNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $title,
        private readonly string $body,
        private readonly array $data = [],
        private readonly string $type = NotificationType::GENERALE_NOTIFICATION,
    ) {}

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

        return [
            'title' => $this->title,
            'body' => $this->body,
            'iconUrl' => $iconUrl,
            'imageUrl' => $iconUrl,
            'data' => array_merge($this->data, [
                'type' => $this->type,
                'iconUrl' => $iconUrl,
                'imageUrl' => $iconUrl,
            ]),
        ];
    }
}
