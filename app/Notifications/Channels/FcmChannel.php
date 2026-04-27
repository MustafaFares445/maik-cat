<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;

class FcmChannel
{
    public function __construct(
        private readonly Messaging $messaging,
    ) {}

    public function send(object $notifiable, Notification $notification): ?array
    {
        if (! method_exists($notification, 'toFcm')) {
            return null;
        }

        $token = $notifiable->routeNotificationFor('fcm', $notification);

        if (! is_string($token) || $token === '') {
            return null;
        }

        /** @var mixed $payload */
        $payload = $notification->toFcm($notifiable);

        if (! is_array($payload)) {
            return null;
        }

        $title = (string) ($payload['title'] ?? '');
        $body = (string) ($payload['body'] ?? '');
        $imageUrl = (string) ($payload['iconUrl'] ?? $payload['icon_url'] ?? $payload['imageUrl'] ?? $payload['image_url'] ?? '');
        $data = is_array($payload['data'] ?? null)
            ? $this->normalizeData($payload['data'])
            : [];

        $messagePayload = [
            'token' => $token,
        ];

        if ($title !== '' || $body !== '') {
            $messagePayload['notification'] = [
                'title' => $title,
                'body' => $body,
            ];

            if ($imageUrl !== '') {
                $messagePayload['notification']['image'] = $imageUrl;
            }
        }

        if ($data !== []) {
            $messagePayload['data'] = $data;
        }

        $message = CloudMessage::fromArray($messagePayload);

        $messageId = $this->messaging->send($message);

        return [
            'message_id' => $messageId,
            'token' => $token,
        ];
    }

    private function normalizeData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            $normalized[(string) $key] = match (true) {
                is_string($value) => $value,
                is_int($value), is_float($value), is_bool($value) => (string) $value,
                is_null($value) => '',
                default => json_encode($value, JSON_THROW_ON_ERROR),
            };
        }

        return $normalized;
    }
}
