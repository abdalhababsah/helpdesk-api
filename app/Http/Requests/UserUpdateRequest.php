<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UserUpdateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'roleId' => ['sometimes', 'string', Rule::exists('roles', 'id')],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('isActive')) {
            $this->merge(['isActive' => filter_var($this->input('isActive'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)]);
        }
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['roleId', 'isActive'])) {
                    $validator->errors()->add('roleId', 'Provide at least one field to change.');
                }
            },
        ];
    }
}
