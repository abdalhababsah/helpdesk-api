<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The bounds the draft card is held to, so an edited draft cannot fail
 * validation in a way the person could not have seen coming.
 */
final class AssistantTicketRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'min:5', 'max:120'],
            'description' => ['required', 'string', 'min:10', 'max:4000'],
            'categoryId' => ['required', 'string', Rule::exists('categories', 'id')->where('is_active', true)],
        ];
    }
}
