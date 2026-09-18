<?php

namespace App\Http\Requests\API\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('fcmToken') && ! $this->filled('fcm_token')) {
            $this->merge([
                'fcm_token' => $this->input('fcmToken'),
            ]);
        }

        if ($this->filled('deviceId') && ! $this->filled('device_id')) {
            $this->merge([
                'device_id' => $this->input('deviceId'),
            ]);
        }

        if ($this->has('fcm_token')) {
            $this->merge([
                'fcm_token' => trim((string) $this->input('fcm_token')),
            ]);
        }

        if ($this->has('device_id')) {
            $this->merge([
                'device_id' => trim((string) $this->input('device_id')),
            ]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'fcm_token' => ['nullable', 'string', 'max:4096'],
        ];
    }
}
