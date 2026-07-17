<?php

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;

class ItemFilterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $filter = $this->input('filter', []);
        $filter = is_array($filter) ? $filter : [];

        $text = null;

        if ($this->filled('text')) {
            $text = $this->input('text');
        } elseif ($this->filled('filter.text')) {
            $text = $this->input('filter.text');
        }

        if ($text !== null) {
            $filter['text'] = $text;

            if (! $this->filled('text')) {
                $this->merge(['text' => $text]);
            }
        }

        if ($this->filled('category_id')) {
            $filter['category_id'] = $this->input('category_id');
        }

        if ($this->filled('car_group')) {
            $filter['car_group'] = $this->input('car_group');
        }

        if ($this->filled('carGroup')) {
            $carGroup = $this->input('carGroup');
            $filter['car_group'] = $carGroup;

            if (! $this->filled('car_group')) {
                $this->merge(['car_group' => $carGroup]);
            }
        }

        if ($this->filled('categoryId')) {
            $categoryId = $this->input('categoryId');
            $filter['category_id'] = $categoryId;

            if (! $this->filled('category_id')) {
                $this->merge(['category_id' => $categoryId]);
            }
        }

        $this->merge(['filter' => $filter]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'text' => ['sometimes', 'string', 'max:255'],
            'category_id' => ['sometimes', 'uuid', 'exists:car_groups,id'],
            'categoryId' => ['sometimes', 'uuid', 'exists:car_groups,id'],
            'car_group' => ['sometimes', 'string', 'max:255'],
            'carGroup' => ['sometimes', 'string', 'max:255'],
            'sort' => ['sometimes', 'string', 'in:created_at,-created_at,serial_code,-serial_code,model,-model'],
            'filter' => ['sometimes', 'array'],
            'filter.text' => ['sometimes', 'string', 'max:255'],
            'filter.category_id' => ['sometimes', 'uuid', 'exists:car_groups,id'],
            'filter.car_group' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
