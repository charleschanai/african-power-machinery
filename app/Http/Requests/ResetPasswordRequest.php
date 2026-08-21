<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()->symbols()],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email is required.',
            'email.email' => 'Email is not valid.',
            'token.required' => 'Security token is required.',
            'password.required' => 'The new password is required.',
            'password.confirmed' => 'The password and its confirmation do not match.',
            'password.min' => 'The password must be at least 10 characters.',
            'password.letters' => 'The password must contain letters.',
            'password.mixedCase' => 'The password must contain both uppercase and lowercase letters.',
            'password.numbers' => 'The password must contain numbers.',
            'password.symbols' => 'The password must contain at least one symbol (e.g. !@#$%).',
        ];
    }
}
