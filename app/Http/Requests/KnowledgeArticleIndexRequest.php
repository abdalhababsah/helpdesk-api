<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class KnowledgeArticleIndexRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:120'],
            'includeRetired' => ['sometimes', 'boolean'],
        ];
    }
}
