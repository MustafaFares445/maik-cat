<?php

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketChangesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'days' => ['sometimes', 'integer', 'min:1', 'max:14'],
            'currency' => ['sometimes', 'string', Rule::in(['USD', 'EUR', 'usd', 'eur'])],
        ];
    }

    public function days(): int
    {
        return (int) $this->integer('days', 14);
    }

    public function currency(): string
    {
        return strtoupper((string) $this->input('currency', 'USD'));
    }
}
