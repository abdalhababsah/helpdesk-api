<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CategoryStoreRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            // Optional. Derived from the name when omitted, and fixed from then
            // on because it is the public filter key.
            'slug' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/', 'unique:categories,slug'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['slug.regex' => 'The slug may contain lower-case letters, numbers and hyphens only.'];
    }
}
