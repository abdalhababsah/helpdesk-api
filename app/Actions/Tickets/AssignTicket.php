<?php

namespace App\Actions\Tickets;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Exceptions\InvalidAssignee;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AssignTicket
{
    use RecordsActions;

    /** Passing null unassigns, returning the ticket to the queue. */
    public function handle(Actor $actor, Ticket $ticket, ?string $assigneeId): Ticket
    {
        $actor->authorize(PermissionSlug::TicketAssign, $ticket);

        if ($assigneeId !== null) {
            $this->assertAssignable($assigneeId);
        }

        $previous = $ticket->assignee_id;

        if ($previous === $assigneeId) {
            return $ticket;
        }

        return DB::transaction(function () use ($actor, $ticket, $assigneeId, $previous): Ticket {
            $ticket->update(['assignee_id' => $assigneeId]);

            $this->record(
                $assigneeId === null ? ActionType::TicketUnassigned : ActionType::TicketAssigned,
                $actor->user,
                $ticket,
                ['from' => $previous, 'to' => $assigneeId],
            );

            return $ticket;
        });
    }

    private function assertAssignable(string $assigneeId): void
    {
        $ok = User::where('id', $assigneeId)
            ->where('is_active', true)
            ->whereRelation('role', 'slug', '!=', RoleSlug::User->value)
            ->exists();

        if (! $ok) {
            throw new InvalidAssignee;
        }
    }
}
