<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCardTemplateRequest extends FormRequest
{
    /**
     * Largest card design accepted, in kilobytes.
     *
     * A card design is shown to every guest on a phone, usually on mobile data,
     * so the ceiling is there to stop a full resolution photo from becoming a
     * multi megabyte download. It is a limit on the file, not on the print
     * resolution, so a design saved at roughly 1080x1620 is plenty.
     */
    private const MAX_DESIGN_KB = 5120;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'card_type' => ['required', Rule::in(['single', 'double'])],
            'front_design' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:' . self::MAX_DESIGN_KB],
            'back_design' => [
                'nullable',
                'image',
                'mimes:png,jpg,jpeg',
                'max:' . self::MAX_DESIGN_KB,
                Rule::requiredIf($this->input('card_type') === 'double'),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }

    public function messages(): array
    {
        return [
            'front_design.required' => 'The front design image is required.',
            'front_design.image' => 'The Front Design file must be an image.',
            'front_design.mimes' => 'Front Design accepts PNG, JPG or JPEG only.',
            'front_design.max' => $this->tooBigMessage('Front Design', 'front_design'),
            'back_design.required' => 'For a DOUBLE card, the back design image is required.',
            'back_design.image' => 'The Back Design file must be an image.',
            'back_design.mimes' => 'Back Design accepts PNG, JPG or JPEG only.',
            'back_design.max' => $this->tooBigMessage('Back Design', 'back_design'),
        ];
    }

    /**
     * Says how big the file actually is and by how much it is over, because
     * "must not exceed 5MB" on a file that is 0.8% over the limit tells the
     * admin nothing about what to do next. It also names the fix, since the
     * usual cause is simply a phone photo saved at full resolution.
     */
    private function tooBigMessage(string $label, string $field): string
    {
        $limitMb = intdiv(self::MAX_DESIGN_KB, 1024);
        $file = $this->file($field);

        if ($file === null) {
            return "{$label} must not exceed {$limitMb}MB.";
        }

        $actualMb = round($file->getSize() / 1048576, 2);

        return "{$label} is {$actualMb}MB, over the {$limitMb}MB limit. "
            . 'Save it smaller and upload again: on a phone pick Medium or Low quality, '
            . 'or on a computer export as JPG rather than PNG. '
            . 'Guests open this card on mobile data, so a smaller file is quicker for them too.';
    }
}
