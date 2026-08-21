<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportCardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please choose a CSV file.',
            'file.file' => 'Please choose a valid file.',
            'file.mimes' => 'The file must be a CSV.',
            'file.max' => 'The file is too large (maximum is 2MB).',
        ];
    }
}
