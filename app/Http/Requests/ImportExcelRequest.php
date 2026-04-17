<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportExcelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls',
                'max:20480',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Only Excel files (.xlsx, .xls) are accepted.',
            'file.max' => 'The file must not be larger than 20 MB.',
        ];
    }
}
