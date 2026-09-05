<?php

namespace App\Queries;

use App\Authorization\Actor;
use App\Enums\PermissionScope;
use App\Enums\PermissionSlug;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Composes the ticket list.
 *
 * Filtering, sorting and pagination all execute in the database against the
 * whole table. Loading rows and narrowing them in PHP would give page two a
 * different row count than page one and make the reported total meaningless.
 */
final class TicketQuery
{
    /**
     * The set of tickets an actor may see at all, before any filter.
     *
     * Authorization is expressed as a predicate rather than a per-row check, so
     * it applies to the count as well as the page. Filtering the rows but not
     * the count would leak how many tickets exist in total.
     *
     * @return Builder<Ticket>
     */
    public function visibleTo(Actor $actor): Builder
    {
        $query = Ticket::query();

        return match ($actor->scopeFor(PermissionSlug::TicketRead)) {
            // No grant at all. An empty set, not an error: the endpoint is
            // still a valid view of a collection that happens to be empty.
            null => $query->whereIn('id', []),
            PermissionScope::Own => $query->where('requester_id', $actor->id()),
            PermissionScope::All => $query,
        };
    }

    /** @return LengthAwarePaginator<int, Ticket> */
    public function paginate(Actor $actor, TicketFilters $filters): LengthAwarePaginator
    {
        $query = $this->visibleTo($actor)
            // Loaded up front so a page of rows costs a fixed number of queries
            // rather than three per ticket.
            ->with([
                'category:id,slug,name',
                'requester:id,name',
                'assignee:id,name',
            ])
            ->withCount('comments');

        $this->applyFilters($query, $actor, $filters);
        $this->applySort($query, $filters);

        return $query->paginate(perPage: $filters->limit, page: $filters->page);
    }

    /** @param  Builder<Ticket>  $query */
    private function applyFilters(Builder $query, Actor $actor, TicketFilters $filters): void
    {
        if ($filters->statuses !== []) {
            $query->whereIn('status', array_map(fn (TicketStatus $s): string => $s->value, $filters->statuses));
        }

        if ($filters->priorities !== []) {
            $query->whereIn('priority', array_map(fn (TicketPriority $p): string => $p->value, $filters->priorities));
        }

        if ($filters->categorySlugs !== []) {
            // Resolved to identifiers first so the predicate uses the category
            // index directly instead of a correlated subquery over slugs.
            $query->whereIn('category_id', Category::whereIn('slug', $filters->categorySlugs)->pluck('id'));
        }

        if ($filters->assignee !== null) {
            match ($filters->assignee) {
                TicketFilters::ASSIGNEE_UNASSIGNED => $query->whereNull('assignee_id'),
                TicketFilters::ASSIGNEE_ME => $query->where('assignee_id', $actor->id()),
                default => $query->where('assignee_id', $filters->assignee),
            };
        }

        if ($filters->overdue !== null) {
            $this->applyOverdue($query, $filters->overdue);
        }

        if ($filters->search !== null) {
            $this->applySearch($query, $filters->search);
        }
    }

    /**
     * Overdue is a stored deadline in the past on a ticket still being worked.
     * A resolved ticket is never overdue however late it was.
     *
     * @param  Builder<Ticket>  $query
     */
    private function applyOverdue(Builder $query, bool $overdue): void
    {
        $unresolved = array_map(fn (TicketStatus $s): string => $s->value, TicketStatus::open());

        if ($overdue) {
            $query->where('due_at', '<', now())->whereIn('status', $unresolved);

            return;
        }

        // The negation of the above, not merely a future deadline: a resolved
        // ticket that missed its deadline still belongs in "not overdue".
        $query->where(function (Builder $inner) use ($unresolved): void {
            $inner->where('due_at', '>=', now())->orWhereNotIn('status', $unresolved);
        });
    }

    /**
     * Fulltext where the terms are long enough for the index, LIKE where they
     * are not.
     *
     * MySQL will not index a token shorter than innodb_ft_min_token_size, so a
     * two-letter search against the index returns nothing at all, which is
     * indistinguishable from having no matches. The fallback is slower but it
     * is the difference between a slow answer and a wrong one.
     *
     * InnoDB adds a row to the fulltext index when its transaction commits, so
     * a search cannot find rows written by a transaction that is still open.
     *
     * @param  Builder<Ticket>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
        $tokens = preg_split('/\s+/', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            return;
        }

        $minimum = (int) config('tickets.search.min_token_size');
        $indexable = array_filter($tokens, fn (string $token): bool => mb_strlen($token) >= $minimum);

        if (count($indexable) === count($tokens)) {
            $query->whereFullText(['subject', 'description'], $this->booleanExpression($tokens), ['mode' => 'boolean']);

            return;
        }

        $query->where(function (Builder $outer) use ($tokens): void {
            foreach ($tokens as $token) {
                $like = '%'.addcslashes($token, '%_\\').'%';

                $outer->where(function (Builder $inner) use ($like): void {
                    $inner->where('subject', 'like', $like)->orWhere('description', 'like', $like);
                });
            }
        });
    }

    /**
     * Every term required, each matched as a prefix. Operator characters are
     * stripped rather than escaped: a user typing a stray bracket means it
     * literally, and boolean mode would otherwise read it as syntax and either
     * error or silently change what was asked.
     *
     * @param  list<string>  $tokens
     */
    private function booleanExpression(array $tokens): string
    {
        $cleaned = [];

        foreach ($tokens as $token) {
            $safe = preg_replace('/[+\-><()~*"@]+/u', '', $token) ?? '';

            if ($safe !== '') {
                $cleaned[] = '+'.$safe.'*';
            }
        }

        return implode(' ', $cleaned);
    }

    /** @param  Builder<Ticket>  $query */
    private function applySort(Builder $query, TicketFilters $filters): void
    {
        $direction = $filters->sortDir->value;

        $query->orderBy($filters->sortBy->column(), $direction);

        // Without a unique tiebreaker, rows sharing a sort value have no defined
        // order between queries, so one can appear on two pages or on none.
        // Identifiers are ULIDs, so this also keeps ties in creation order.
        $query->orderBy('id', $direction);
    }
}
