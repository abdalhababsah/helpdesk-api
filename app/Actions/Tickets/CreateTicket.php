<?php

namespace App\Actions\Tickets;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
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

        return $this->createFor($actor->user, $subject, $description, $categoryId, $priority, TicketSource::Direct, null);
    }

    /**
     * The creation itself, with no permission check.
     *
     * The assistant path calls this after proving the conversation belongs to
     * the requester, which is a different rule from holding a grant: a user
     * raises tickets through the assistant and holds no ticket:create.
     */
    public function createFor(
        User $requester,
        string $subject,
        string $description,
        string $categoryId,
        TicketPriority $priority,
        TicketSource $source,
        ?string $conversationId,
    ): Ticket {
        return DB::transaction(function () use ($requester, $subject, $description, $categoryId, $priority, $source, $conversationId): Ticket {
            $now = now();

            $ticket = Ticket::create([
                'subject' => $subject,
                'description' => $description,
                'status' => TicketStatus::Open,
                'priority' => $priority,
                'source' => $source,
                'conversation_id' => $conversationId,
                'category_id' => $categoryId,
                // The raiser is always the caller. Creating on someone else's
                // behalf would make the "own" scope meaningless.
                'requester_id' => $requester->getKey(),
                'due_at' => $now->copy()->addHours($priority->slaHours()),
            ]);

            $this->record(ActionType::TicketCreated, $requester, $ticket, [
                'priority' => $priority->value,
                'category_id' => $categoryId,
                'source' => $source->value,
            ]);

            return $ticket;
        });
    }
}
