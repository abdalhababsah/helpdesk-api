<?php

namespace App\Http\Requests;

use App\Enums\TicketPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TicketStoreRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'min:5', 'max:200'],
            'description' => ['required', 'string', 'min:10', 'max:10000'],
            // Retired categories stay filterable but cannot take new tickets.
            'categoryId' => ['required', 'string', Rule::exists('categories', 'id')->where('is_active', true)],
            'priority' => ['sometimes', Rule::enum(TicketPriority::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'subject.min' => 'Give the ticket a subject of at least 5 characters.',
            'description.min' => 'Describe the problem in at least 10 characters.',
            'categoryId.exists' => 'Choose a category that is currently in use.',
        ];
    }
}
