<?php

namespace App\Actions\Tickets;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Status, priority and category share one permission and one request, so they
 * are applied together. Each change is recorded separately, because "priority
 * raised to urgent" and "status moved to resolved" are different facts.
 */
final class TriageTicket
{
    use RecordsActions;

    public function handle(
        Actor $actor,
        Ticket $ticket,
        ?TicketStatus $status = null,
        ?TicketPriority $priority = null,
        ?string $categoryId = null,
    ): Ticket {
        $actor->authorize(PermissionSlug::TicketTriage, $ticket);

        if ($status !== null && $status !== $ticket->status && ! $ticket->status->canTransitionTo($status)) {
            throw new InvalidStatusTransition($ticket->status, $status);
        }

        return DB::transaction(function () use ($actor, $ticket, $status, $priority, $categoryId): Ticket {
            if ($priority !== null && $priority !== $ticket->priority) {
                $from = $ticket->priority;
                $ticket->priority = $priority;
                // The deadline follows the priority, otherwise raising a ticket
                // to urgent would leave it due a week out.
                $ticket->due_at = $ticket->created_at->copy()->addHours($priority->slaHours());

                $this->record(ActionType::TicketPriorityChanged, $actor->user, $ticket, [
                    'from' => $from->value,
                    'to' => $priority->value,
                    'due_at' => $ticket->due_at->toIso8601String(),
                ]);
            }

            if ($categoryId !== null && $categoryId !== $ticket->category_id) {
                $from = $ticket->category_id;
                $ticket->category_id = $categoryId;

                $this->record(ActionType::TicketCategoryChanged, $actor->user, $ticket, [
                    'from' => $from,
                    'to' => $categoryId,
                ]);
            }

            if ($status !== null && $status !== $ticket->status) {
                $from = $ticket->status;
                $ticket->status = $status;
                $this->applyLifecycleStamps($ticket, $status);

                $this->record(ActionType::TicketStatusChanged, $actor->user, $ticket, [
                    'from' => $from->value,
                    'to' => $status->value,
                ]);
            }

            $ticket->save();

            return $ticket;
        });
    }

    /**
     * resolved_at drives the average resolution metric, so reopening must clear
     * it. Leaving it set would count a ticket that is being worked again as
     * finished, and quietly flatter the average.
     */
    private function applyLifecycleStamps(Ticket $ticket, TicketStatus $status): void
    {
        $now = now();

        if ($status->isFinished()) {
            $ticket->resolved_at ??= $now;
            $ticket->closed_at = $status === TicketStatus::Closed ? $now : null;

            return;
        }

        $ticket->resolved_at = null;
        $ticket->closed_at = null;
    }
}
