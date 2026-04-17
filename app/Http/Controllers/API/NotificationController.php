<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $notifications = $user->notifications()->latest()->get();

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

        return response()->json([
            'message' => 'Notification marked as read.',
            'data' => $notification->fresh(),
        ]);
    }
}
