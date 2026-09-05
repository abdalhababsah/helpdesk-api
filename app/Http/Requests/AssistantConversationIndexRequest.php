<?php

namespace App\Http\Requests;

use App\Enums\AssistantOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssistantConversationIndexRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'outcome' => ['sometimes', Rule::enum(AssistantOutcome::class)],
            'participant' => ['sometimes', Rule::in(['user', 'assistant_guest'])],
        ];
    }
}
