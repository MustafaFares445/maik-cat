<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\Auth\ForgotPasswordRequest;
use App\Http\Requests\API\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::query()->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 422);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account is currently inactive. Please contact support.',
            ], 403);
        }

        $fcmToken = $validated['fcm_token'] ?? null;

        if (is_string($fcmToken) && $fcmToken !== '') {
            $user->forceFill(['fcm_token' => $fcmToken])->save();
        }

        $token = $user->createToken('mobile-api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'preferred_language' => $user->preferredLanguageOrDefault(),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        $exists = User::query()->where('email', $request->input('email'))->exists();

        if (! $exists) {
            return response()->json(['message' => 'email not found.', Response::HTTP_BAD_REQUEST]);
        }

        return response()->json([
            'message' => 'password reset link was sent.',
        ]);
    }
}
