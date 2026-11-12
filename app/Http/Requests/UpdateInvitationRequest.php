<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Invitation;

class UpdateInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'guest_id' => ['required', 'integer', 'exists:guests,id'],
            'card_id' => ['nullable', 'integer', 'exists:cards,id'],
            'card_template_id' => ['nullable', 'integer', 'exists:card_templates,id'],

            'phone' => ['required', 'string', 'max:32'],

            'event_name' => ['nullable', 'string', 'max:255'],
            'event_date' => ['nullable', 'date'],
            'event_time' => ['nullable', 'string', 'max:32'],
            'venue' => ['nullable', 'string', 'max:255'],
            'wedding_details' => ['nullable', 'string', 'max:5000'],

            'message' => ['required', 'string', 'max:5000'],
            'reminder_message' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * The table has a composite UNIQUE(guest_id, card_id). Rule::unique cannot
     * express a composite key, so the check is done here instead. The row being
     * edited is ignored, otherwise saving without changing anything would
     * always collide with itself.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $guestId = $this->input('guest_id');
            $cardId = $this->input('card_id');

            if (! is_numeric($guestId)) {
                return;
            }

            $duplicate = Invitation::query()
                ->where('guest_id', (int) $guestId)
                ->when(
                    $cardId === null || $cardId === '',
                    fn ($q) => $q->whereNull('card_id'),
                    fn ($q) => $q->where('card_id', (int) $cardId)
                )
                ->when(
                    ($id = $this->route('invitation')?->id) !== null,
                    fn ($q) => $q->where('id', '!=', $id)
                )
                ->exists();

            if ($duplicate) {
                $validator->errors()->add(
                    'card_id',
                    'This guest already has an invitation saved for that card.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'guest_id.required' => 'Please choose a guest.',
            'guest_id.exists' => 'That guest no longer exists. Pick another one.',
            'card_id.exists' => 'That card no longer exists. Pick another one.',
            'phone.required' => 'The phone number is required.',
            'message.required' => 'The invitation message is required.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'event_name' => $this->nullableString('event_name'),
            'event_time' => $this->nullableString('event_time'),
            'venue' => $this->nullableString('venue'),
            'wedding_details' => $this->nullableString('wedding_details'),
            'reminder_message' => $this->nullableString('reminder_message'),
        ]);
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->input($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
