<?php

namespace App\Actions\Tickets;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

final class CreateTicket
{
    use RecordsActions;

    public function handle(
        Actor $actor,
        string $subject,
        string $description,
        string $categoryId,
        TicketPriority $priority = TicketPriority::Medium,
    ): Ticket {
        $actor->authorize(PermissionSlug::TicketCreate);

        return DB::transaction(function () use ($actor, $subject, $description, $categoryId, $priority): Ticket {
            $now = now();

            $ticket = Ticket::create([
                'subject' => $subject,
                'description' => $description,
                'status' => TicketStatus::Open,
                'priority' => $priority,
                'category_id' => $categoryId,
                // The raiser is always the actor. Creating on someone else's
                // behalf would make the "own" scope meaningless.
                'requester_id' => $actor->id(),
                'due_at' => $now->copy()->addHours($priority->slaHours()),
            ]);

            $this->record(ActionType::TicketCreated, $actor->user, $ticket, [
                'priority' => $priority->value,
                'category_id' => $categoryId,
            ]);

            return $ticket;
        });
    }
}
