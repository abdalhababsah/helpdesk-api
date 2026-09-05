<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TicketCommentStoreRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['body.required' => 'Write a reply before sending.'];
    }
}
