<?php

namespace App\Http\Requests;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class TicketUpdateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(TicketStatus::class)],
            'priority' => ['sometimes', Rule::enum(TicketPriority::class)],
            'categoryId' => ['sometimes', 'string', Rule::exists('categories', 'id')],
            // Nullable on purpose: sending null returns the ticket to the queue.
            // Absent means leave the assignment alone, which is a different
            // intent and must stay distinguishable.
            'assigneeId' => ['sometimes', 'nullable', 'string', Rule::exists('users', 'id')],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['status', 'priority', 'categoryId', 'assigneeId'])) {
                    $validator->errors()->add('status', 'Provide at least one field to change.');
                }
            },
        ];
    }
}
