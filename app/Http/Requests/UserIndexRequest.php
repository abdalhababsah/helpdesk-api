<?php

namespace App\Http\Requests;

use App\Enums\RoleSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UserIndexRequest extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED = ['page', 'limit', 'role', 'isActive', 'search'];

    protected function prepareForValidation(): void
    {
        if ($this->has('isActive')) {
            $this->merge(['isActive' => filter_var($this->query('isActive'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['integer', 'min:1'],
            'limit' => ['integer', 'min:1', 'max:'.config('tickets.pagination.max_limit')],
            'role' => [Rule::enum(RoleSlug::class)],
            'isActive' => ['boolean'],
            'search' => ['string', 'min:1', 'max:100'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_diff(array_keys($this->query()), self::ALLOWED) as $unknown) {
                    $validator->errors()->add((string) $unknown, "Unknown query parameter [{$unknown}].");
                }
            },
        ];
    }
}
