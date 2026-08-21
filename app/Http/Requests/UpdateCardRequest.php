<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'guest_id' => ['required', 'exists:guests,id'],
            'card_template_id' => ['nullable', 'exists:card_templates,id'],
            'card_type' => ['required', Rule::in(['single', 'double'])],
            'card_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('cards', 'card_number')->ignore($this->route('card')),
            ],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'event_name' => ['nullable', 'string', 'max:255'],
            'event_date' => ['nullable', 'date'],
            'event_time' => ['nullable', 'date_format:H:i'],
            'venue' => ['nullable', 'string', 'max:255'],
            'table_number' => ['nullable', 'string', 'max:50'],
            'seat_number' => ['nullable', 'string', 'max:50'],
            'special_message' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'guest_id.required' => 'Please select a guest.',
            'guest_id.exists' => 'This guest does not exist.',
            'card_template_id.exists' => 'This template does not exist.',
            'card_number.unique' => 'This card number is already in use.',
            'card_type.required' => 'Card type is required.',
            'card_type.in' => 'Card type is not valid.',
            'expires_at.after' => 'The expiry date must be in the future.',
            'event_time.date_format' => 'Event time is not valid.',
        ];
    }
}
