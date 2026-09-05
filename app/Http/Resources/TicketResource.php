<?php

namespace App\Http\Resources;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ticket as it appears in a list.
 *
 * The description is omitted on purpose: it is a TEXT column, nothing in the
 * table renders it, and carrying it on every row of every page is bytes spent
 * on nothing. It remains searchable.
 *
 * @mixin Ticket
 */
class TicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'source' => $this->source->value,
            // Nested rather than bare identifiers, so a table renders without a
            // second request or a client-side join.
            'category' => [
                'id' => $this->category->id,
                'slug' => $this->category->slug,
                'name' => $this->category->name,
            ],
            'requester' => [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
            ],
            'assignee' => $this->assignee === null ? null : [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ],
            'dueAt' => $this->due_at->toIso8601String(),
            // Computed here so the client does not reimplement the rule and
            // drift from it.
            'isOverdue' => $this->is_overdue,
            'resolvedAt' => $this->resolved_at?->toIso8601String(),
            'commentCount' => (int) ($this->comments_count ?? 0),
            'createdAt' => $this->created_at->toIso8601String(),
            'updatedAt' => $this->updated_at->toIso8601String(),
        ];
    }
}
