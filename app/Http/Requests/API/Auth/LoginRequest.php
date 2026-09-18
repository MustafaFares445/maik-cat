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

        if ($this->has('fcm_token')) {
            $this->merge([
                'fcm_token' => trim((string) $this->input('fcm_token')),
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
            'fcm_token' => ['required', 'string', 'max:4096'],
        ];
    }
}
