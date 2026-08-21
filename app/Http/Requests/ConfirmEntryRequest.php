<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmEntryRequest extends FormRequest
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
            'gate_name' => ['nullable', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'number_of_guests' => ['nullable', 'integer', 'min:1', 'max:2'],
            'verification_method' => ['nullable', Rule::in(['qr', 'card_number', 'guest_name'])],
        ];
    }

    public function messages(): array
    {
        return [
            'qr_token.required_without' => 'Card details are required.',
            'card_number.required_without' => 'Card details are required.',
            'number_of_guests.min' => 'The number of guests entering must be at least 1.',
            'number_of_guests.max' => 'The number of guests entering cannot exceed 2.',
        ];
    }

    public function identifier(): string
    {
        return $this->filled('qr_token') ? $this->input('qr_token') : $this->input('card_number');
    }

    public function context(): array
    {
        return [
            'gate_name' => $this->input('gate_name'),
            'device_name' => $this->input('device_name'),
            'number_of_guests' => $this->input('number_of_guests'),
            'verification_method' => $this->input('verification_method'),
            'ip_address' => $this->ip(),
            'user_agent' => $this->userAgent(),
        ];
    }
}
