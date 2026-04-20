<?php

namespace App\Http\Requests\API;

use App\Enums\AppPlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'platform' => ['required', Rule::in(AppPlatform::values())],
            'version' => ['required', 'string', 'regex:/^\d+\.\d+\.\d+$/'],
        ];
    }

    public function platform(): string
    {
        return (string) $this->input('platform');
    }

    public function version(): string
    {
        return (string) $this->input('version');
    }
}
