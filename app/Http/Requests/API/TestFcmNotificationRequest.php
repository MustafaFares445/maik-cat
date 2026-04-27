<?php

namespace App\Http\Requests\API;

use App\Enums\NotificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TestFcmNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:500'],
            'data' => ['nullable', 'array'],
            'type' => ['nullable', 'string', Rule::in(NotificationType::values())],
        ];
    }
}
