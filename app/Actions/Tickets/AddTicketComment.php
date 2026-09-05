<?php

namespace App\Actions\Tickets;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Exceptions\TicketIsClosed;
use App\Models\Ticket;
use App\Models\TicketComment;
use Illuminate\Support\Facades\DB;

final class AddTicketComment
{
    use RecordsActions;

    public function handle(Actor $actor, Ticket $ticket, string $body): TicketComment
    {
        $actor->authorize(PermissionSlug::TicketComment, $ticket);

        if ($ticket->status->isTerminal()) {
            throw new TicketIsClosed;
        }

        return DB::transaction(function () use ($actor, $ticket, $body): TicketComment {
            $comment = $ticket->comments()->create([
                'author_id' => $actor->id(),
                'body' => $body,
            ]);

            // Subject is the ticket, not the comment: a reader wants the
            // ticket's timeline, and the comment is a detail of it.
            $this->record(ActionType::TicketCommented, $actor->user, $ticket, [
                'comment_id' => $comment->getKey(),
            ]);

            return $comment;
        });
    }
}
