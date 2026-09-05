<?php

namespace App\Queries;

use App\Enums\SortDirection;
use App\Enums\TicketPriority;
use App\Enums\TicketSort;
use App\Enums\TicketStatus;

/**
 * The parsed, validated shape of a ticket list request.
 *
 * Separate from the HTTP request so the query can be exercised without one, and
 * so everything reaching the builder is already a typed value rather than
 * whatever arrived in the query string.
 */
final readonly class TicketFilters
{
    public const ASSIGNEE_ME = 'me';

    public const ASSIGNEE_UNASSIGNED = 'unassigned';

    /**
     * @param  list<TicketStatus>  $statuses
     * @param  list<TicketPriority>  $priorities
     * @param  list<string>  $categorySlugs
     */
    public function __construct(
        public int $page = 1,
        public int $limit = 20,
        public array $statuses = [],
        public array $priorities = [],
        public array $categorySlugs = [],
        public ?string $assignee = null,
        public ?string $search = null,
        public ?bool $overdue = null,
        public TicketSort $sortBy = TicketSort::CreatedAt,
        public SortDirection $sortDir = SortDirection::Desc,
    ) {}
}
