<?php

namespace App\Http\Requests;

use App\Enums\SortDirection;
use App\Enums\TicketPriority;
use App\Enums\TicketSort;
use App\Enums\TicketStatus;
use App\Queries\TicketFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class TicketIndexRequest extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED = [
        'page', 'limit', 'status', 'priority', 'category',
        'assignee', 'search', 'overdue', 'sortBy', 'sortDir',
    ];

    /** Multi-value filters arrive comma separated, as the brief specifies. */
    protected function prepareForValidation(): void
    {
        foreach (['status', 'priority', 'category'] as $key) {
            if ($this->filled($key)) {
                $this->merge([$key => array_values(array_filter(explode(',', (string) $this->query($key))))]);
            }
        }

        // A browser serialises a boolean as the string "true", which Laravel's
        // boolean rule rejects. Normalising here keeps the natural query string
        // working instead of forcing clients to send 1 and 0.
        if ($this->has('overdue')) {
            $this->merge([
                'overdue' => filter_var($this->query('overdue'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['integer', 'min:1'],
            'limit' => ['integer', 'min:1', 'max:'.config('tickets.pagination.max_limit')],

            'status' => ['array'],
            'status.*' => [Rule::enum(TicketStatus::class)],

            'priority' => ['array'],
            'priority.*' => [Rule::enum(TicketPriority::class)],

            'category' => ['array'],
            // An unknown slug is rejected rather than silently returning
            // nothing, because an empty result from a typo is indistinguishable
            // from a genuine empty result.
            'category.*' => ['string', Rule::exists('categories', 'slug')],

            'assignee' => ['string', $this->assigneeRule()],
            'search' => ['string', 'min:1', 'max:100'],
            'overdue' => ['boolean'],

            'sortBy' => [Rule::enum(TicketSort::class)],
            'sortDir' => [Rule::enum(SortDirection::class)],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // Rejected rather than ignored: a typo in a filter name would
                // otherwise return an unfiltered page that looks correct.
                foreach (array_diff(array_keys($this->query()), self::ALLOWED) as $unknown) {
                    $validator->errors()->add((string) $unknown, "Unknown query parameter [{$unknown}].");
                }
            },
        ];
    }

    public function toFilters(): TicketFilters
    {
        return new TicketFilters(
            page: $this->integer('page', 1),
            limit: $this->integer('limit', (int) config('tickets.pagination.default_limit')),
            statuses: array_map(TicketStatus::from(...), $this->validated('status', [])),
            priorities: array_map(TicketPriority::from(...), $this->validated('priority', [])),
            categorySlugs: $this->validated('category', []),
            assignee: $this->filled('assignee') ? (string) $this->query('assignee') : null,
            search: $this->filled('search') ? (string) $this->query('search') : null,
            // has() rather than boolean(), which cannot tell an absent filter
            // from an explicit false.
            overdue: $this->has('overdue') ? $this->boolean('overdue') : null,
            sortBy: TicketSort::tryFrom((string) $this->query('sortBy')) ?? TicketSort::CreatedAt,
            sortDir: SortDirection::tryFrom((string) $this->query('sortDir')) ?? SortDirection::Desc,
        );
    }

    /**
     * Deliberately no database lookup. Confirming whether an identifier belongs
     * to a real account would turn this filter into a way to test for one.
     */
    private function assigneeRule(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            if (in_array($value, [TicketFilters::ASSIGNEE_ME, TicketFilters::ASSIGNEE_UNASSIGNED], true)) {
                return;
            }

            if (! is_string($value) || preg_match('/^[0-9a-z]{26}$/i', $value) !== 1) {
                $fail('The assignee filter must be me, unassigned, or a user identifier.');
            }
        };
    }
}
