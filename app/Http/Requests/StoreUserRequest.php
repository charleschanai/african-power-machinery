<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            // The admin sets the first password, so it has to be typed twice.
            // An emailed "set your own password" link needs a working SMTP
            // server, which this install does not assume exists.
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(User::ROLES)],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Another user already uses this email address.',
            'password.confirmed' => 'The two passwords do not match.',
            'password.min' => 'The password must be at least 8 characters.',
            'role.in' => 'Role must be either admin or staff.',
        ];
    }
}
