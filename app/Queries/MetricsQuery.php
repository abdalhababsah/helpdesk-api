<?php

namespace App\Queries;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * The numbers behind the admin dashboard.
 *
 * Every figure is aggregated in the database. Counting in PHP would mean
 * loading the whole table to produce four numbers.
 */
final class MetricsQuery
{
    /** @return array<string, mixed> */
    public function overview(): array
    {
        return [
            'byStatus' => $this->byStatus(),
            'byCategory' => $this->byCategory(),
            'averageResolutionHours' => $this->averageResolutionHours(),
            'totals' => $this->totals(),
        ];
    }

    /**
     * Every status appears, including the ones with nothing in them. A chart
     * that silently drops empty buckets misreads as missing data.
     *
     * @return array<string, int>
     */
    private function byStatus(): array
    {
        $counted = Ticket::query()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $result = [];
        foreach (TicketStatus::cases() as $status) {
            $result[$status->value] = (int) ($counted[$status->value] ?? 0);
        }

        return $result;
    }

    /**
     * Left joined from categories so a category with no tickets still appears
     * at zero rather than vanishing from the breakdown.
     *
     * @return list<array{slug: string, name: string, total: int}>
     */
    private function byCategory(): array
    {
        // The query builder rather than the model: these rows are report
        // output, not categories, and hydrating a model per row to read one
        // aggregate off it would be work for nothing.
        return DB::table('categories')
            ->leftJoin('tickets', function ($join): void {
                $join->on('tickets.category_id', '=', 'categories.id')
                    ->whereNull('tickets.deleted_at');
            })
            ->selectRaw('categories.slug, categories.name, COUNT(tickets.id) AS total')
            ->groupBy('categories.id', 'categories.slug', 'categories.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => [
                'slug' => (string) $row->slug,
                'name' => (string) $row->name,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    /** Null rather than zero when nothing has been resolved: no data is not an average of none. */
    private function averageResolutionHours(): ?float
    {
        $minutes = Ticket::query()
            ->whereNotNull('resolved_at')
            ->avg(DB::raw('TIMESTAMPDIFF(MINUTE, created_at, resolved_at)'));

        return $minutes === null ? null : round((float) $minutes / 60, 1);
    }

    /** @return array<string, int> */
    private function totals(): array
    {
        $unresolved = array_map(fn (TicketStatus $s): string => $s->value, TicketStatus::open());
        $placeholders = implode(',', array_fill(0, count($unresolved), '?'));

        $row = Ticket::query()
            ->selectRaw(
                'COUNT(*) AS total,'
                ."SUM(status IN ({$placeholders})) AS unresolved,"
                ."SUM(due_at < ? AND status IN ({$placeholders})) AS overdue,"
                ."SUM(assignee_id IS NULL AND status IN ({$placeholders})) AS unassigned",
                [...$unresolved, now(), ...$unresolved, ...$unresolved],
            )
            ->first();

        return [
            'tickets' => (int) ($row->total ?? 0),
            'unresolved' => (int) ($row->unresolved ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
            'unassigned' => (int) ($row->unassigned ?? 0),
        ];
    }
}
