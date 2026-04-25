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
            'weight' => ['required', 'numeric', 'min:0'],
            'ptPpm' => ['required', 'numeric', 'min:0'],
            'pdPpm' => ['required', 'numeric', 'min:0'],
            'rhPpm' => ['required', 'numeric', 'min:0'],
            'recoveryRate' => ['required', 'numeric', 'min:0', 'max:1'],
            'currency' => ['required', 'string', Rule::in(['USD', 'EUR', 'usd', 'eur'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ptPpm' => $this->input('ptPpm', $this->input('pt_ppm')),
            'pdPpm' => $this->input('pdPpm', $this->input('pd_ppm')),
            'rhPpm' => $this->input('rhPpm', $this->input('rh_ppm')),
            'recoveryRate' => $this->input('recoveryRate', $this->input('recovery_rate')),
        ]);
    }

    public function currency(): string
    {
        return strtoupper((string) $this->input('currency', 'USD'));
    }
}
