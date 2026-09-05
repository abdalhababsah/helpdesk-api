<?php

namespace App\Actions\Tickets;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Soft delete. The row stays so the action log still resolves its subject and
 * the ticket can be recovered; every read path filters it out.
 */
final class DeleteTicket
{
    use RecordsActions;

    public function handle(Actor $actor, Ticket $ticket): void
    {
        $actor->authorize(PermissionSlug::TicketDelete, $ticket);

        DB::transaction(function () use ($actor, $ticket): void {
            $ticket->delete();

            $this->record(ActionType::TicketDeleted, $actor->user, $ticket, [
                'status' => $ticket->status->value,
                'subject' => $ticket->subject,
            ]);
        });
    }
}
