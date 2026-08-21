<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qr_token' => ['required_without:card_number', 'string'],
            'card_number' => ['required_without:qr_token', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'qr_token.required_without' => 'Card details are required.',
            'card_number.required_without' => 'Card details are required.',
        ];
    }

    public function identifier(): string
    {
        return $this->filled('qr_token') ? $this->input('qr_token') : $this->input('card_number');
    }
}
