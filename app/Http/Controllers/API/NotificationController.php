<?php

namespace App\Http\Controllers\API;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\TestFcmNotificationRequest;
use App\Models\User;
use App\Notifications\TestFcmNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use JsonException;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $notifications = $user->notifications()
            ->latest()
            ->get()
            ->map(fn (DatabaseNotification $notification) => $this->withIconUrl($notification));

        return response()->json([
            'data' => $notifications,
            'unreadCount' => $notifications->whereNull('read_at')->count(),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Notifications marked as read.',
            'unreadCount' => $user->notifications()->whereNull('read_at')->count(),
        ]);
    }

    public function markAsRead(Request $request, DatabaseNotification $notification): JsonResponse
    {
        $user = $request->user();

        abort_unless($notification->notifiable_id === $user->getKey(), 404);

        if (is_null($notification->read_at)) {
            $notification->markAsRead();
        }

        $freshNotification = $notification->fresh();

        return response()->json([
            'message' => 'Notification marked as read.',
            'data' => $this->withIconUrl(
                $freshNotification instanceof DatabaseNotification ? $freshNotification : $notification,
            ),
        ]);
    }

    public function sendTestFcm(TestFcmNotificationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! is_string($user->fcm_token) || $user->fcm_token === '') {
            return response()->json([
                'message' => 'User does not have an FCM token.',
            ], 422);
        }

        $validated = $request->validated();

        $user->notify(new TestFcmNotification(
            title: $validated['title'],
            body: $validated['body'],
            data: $validated['data'] ?? [],
            type: $validated['type'] ?? NotificationType::GENERALE_NOTIFICATION,
        ));

        return response()->json([
            'message' => 'Test notification sent successfully.',
        ]);
    }

    private function withIconUrl(DatabaseNotification $notification): DatabaseNotification
    {
        $data = $notification->data;

        if (is_string($data)) {
            try {
                $data = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return $notification;
            }
        }

        if (! is_array($data)) {
            return $notification;
        }

        $type = $data['type'] ?? null;

        if (! is_string($type)) {
            return $notification;
        }

        $iconUrl = NotificationType::iconUrl($type);

        $data['icon_url'] ??= $iconUrl;
        $data['image_url'] ??= $iconUrl;
        $notification->setAttribute('data', $data);

        return $notification;
    }
}
