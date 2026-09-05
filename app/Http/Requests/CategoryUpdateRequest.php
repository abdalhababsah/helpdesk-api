<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CategoryUpdateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
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
                if (! $this->hasAny(['name', 'isActive'])) {
                    $validator->errors()->add('name', 'Provide at least one field to change.');
                }
            },
        ];
    }
}
