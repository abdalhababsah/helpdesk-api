<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssistantMessageRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }
}
