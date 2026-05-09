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
            'fcm_token' => ['nullable', 'string', 'max:4096'],
        ];
    }
}
