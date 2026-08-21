<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCardTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'card_type' => ['required', Rule::in(['single', 'double'])],
            'front_design' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:5120'],
            'back_design' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:5120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }

    public function messages(): array
    {
        return [
            'front_design.image' => 'The Front Design file must be an image.',
            'front_design.mimes' => 'Front Design accepts PNG, JPG or JPEG only.',
            'front_design.max' => 'Front Design must not exceed 5MB.',
            'back_design.image' => 'The Back Design file must be an image.',
            'back_design.mimes' => 'Back Design accepts PNG, JPG or JPEG only.',
            'back_design.max' => 'Back Design must not exceed 5MB.',
        ];
    }
}
