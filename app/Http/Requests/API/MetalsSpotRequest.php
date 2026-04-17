<?php

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MetalsSpotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currency' => ['sometimes', 'string', Rule::in(['USD', 'EUR', 'usd', 'eur'])],
            'unit' => ['sometimes', 'string', Rule::in(['oz', 'gram', 'both'])],
            'metals' => ['sometimes', 'string'],
        ];
    }

    public function currency(): string
    {
        return strtoupper((string) $this->input('currency', 'USD'));
    }

    public function unit(): string
    {
        return (string) $this->input('unit', 'both');
    }

    /**
     * @return array<int, string>
     */
    public function requestedMetals(): array
    {
        if (! $this->filled('metals')) {
            return [];
        }

        return collect(explode(',', (string) $this->query('metals')))
            ->map(fn(string $metal): string => strtolower(trim($metal)))
            ->filter()
            ->values()
            ->all();
    }
}
