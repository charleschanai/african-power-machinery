<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'q.required' => 'Enter a name or card number.',
        ];
    }
}
