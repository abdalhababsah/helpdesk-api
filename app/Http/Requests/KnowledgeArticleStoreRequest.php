<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class KnowledgeArticleStoreRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:5', 'max:120'],
            'body' => ['required', 'string', 'min:20', 'max:20000'],
            'keywords' => ['sometimes', 'string', 'max:255'],
            'categoryId' => ['sometimes', 'nullable', 'string', Rule::exists('categories', 'id')],
        ];
    }
}
