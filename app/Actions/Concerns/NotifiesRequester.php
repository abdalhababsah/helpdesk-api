<?php

namespace App\Actions\Concerns;

use App\Authorization\Actor;
use App\Models\Ticket;
use Illuminate\Notifications\Notification;

trait NotifiesRequester
{
    /**
     * Emails the person who raised the ticket, when there is any point.
     *
     * Two cases are skipped. Nobody needs an email about something they just
     * did themselves, which is what would happen every time an agent updates
     * their own ticket. And a deactivated account cannot act on the message,
     * so sending it is mail nobody will read.
     */
    protected function notifyRequester(Ticket $ticket, Actor $actor, Notification $notification): void
    {
        $requester = $ticket->requester;

        if ($requester === null || $requester->getKey() === $actor->id() || ! $requester->is_active) {
            return;
        }

        $requester->notify($notification);
    }
}
