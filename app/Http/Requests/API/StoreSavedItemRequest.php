<?php

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;

class StoreSavedItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'uuid', 'exists:items,id'],
            'itemId' => ['sometimes', 'uuid', 'exists:items,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('itemId') && ! $this->filled('item_id')) {
            $this->merge([
                'item_id' => $this->input('itemId'),
            ]);
        }
    }
}
