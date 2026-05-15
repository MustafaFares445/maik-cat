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
            'weightUnit' => ['nullable', 'string', Rule::in(['g', 'kg', 'G', 'KG'])],
            'ptPpm' => ['required', 'numeric', 'min:0'],
            'pdPpm' => ['required', 'numeric', 'min:0'],
            'rhPpm' => ['required', 'numeric', 'min:0'],
            'ptUsdPerGram' => ['nullable', 'numeric', 'min:0'],
            'pdUsdPerGram' => ['nullable', 'numeric', 'min:0'],
            'rhUsdPerGram' => ['nullable', 'numeric', 'min:0'],
            'ptRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pdRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rhRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'humidityRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => ['required', 'string', Rule::in(['USD', 'EUR', 'usd', 'eur'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'weightUnit' => $this->input('weightUnit', $this->input('weight_unit', 'g')),
            'ptPpm' => $this->input('ptPpm', $this->input('pt_ppm')),
            'pdPpm' => $this->input('pdPpm', $this->input('pd_ppm')),
            'rhPpm' => $this->input('rhPpm', $this->input('rh_ppm')),
            'ptUsdPerGram' => $this->input('ptUsdPerGram', $this->input('pt_usd_per_gram')),
            'pdUsdPerGram' => $this->input('pdUsdPerGram', $this->input('pd_usd_per_gram')),
            'rhUsdPerGram' => $this->input('rhUsdPerGram', $this->input('rh_usd_per_gram')),
            'ptRate' => $this->input('ptRate', $this->input('pt_rate')),
            'pdRate' => $this->input('pdRate', $this->input('pd_rate')),
            'rhRate' => $this->input('rhRate', $this->input('rh_rate')),
            'humidityRate' => $this->input('humidityRate', $this->input('humidity_rate')),
        ]);
    }

    public function currency(): string
    {
        return strtoupper((string) $this->input('currency', 'USD'));
    }
}
