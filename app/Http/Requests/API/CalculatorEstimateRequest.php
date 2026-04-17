<?php

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CalculatorEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'uuid', 'exists:items,id'],
            'recovery_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'currency' => ['sometimes', 'string', Rule::in(['USD', 'EUR', 'usd', 'eur'])],
        ];
    }

    public function currency(): string
    {
        return strtoupper((string) $this->input('currency', 'USD'));
    }
}
