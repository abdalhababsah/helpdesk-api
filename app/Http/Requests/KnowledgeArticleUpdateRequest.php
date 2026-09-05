<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class KnowledgeArticleUpdateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'min:5', 'max:120'],
            'body' => ['sometimes', 'string', 'min:20', 'max:20000'],
            'keywords' => ['sometimes', 'string', 'max:255'],
            'categoryId' => ['sometimes', 'nullable', 'string', Rule::exists('categories', 'id')],
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
                if (! $this->hasAny(['title', 'body', 'keywords', 'categoryId', 'isActive'])) {
                    $validator->errors()->add('title', 'Provide at least one field to change.');
                }
            },
        ];
    }
}
