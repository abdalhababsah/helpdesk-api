<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class UserStoreRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            // Length carries most of the strength; the character classes stop
            // the obvious dictionary choices without pushing people to a
            // sticky note.
            'password' => ['required', Password::min(10)->letters()->numbers()],
            'roleId' => ['required', 'string', Rule::exists('roles', 'id')],
        ];
    }

    /** Stored lower-cased so the unique index and every lookup agree. */
    protected function prepareForValidation(): void
    {
        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower((string) $this->input('email'))]);
        }
    }
}
