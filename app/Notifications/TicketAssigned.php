<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the person who raised a ticket that someone has picked it up.
 *
 * Only sent when a ticket gains an owner. Being handed back to the queue is
 * not news the requester can act on, and telling them their ticket was put
 * down again reads worse than saying nothing.
 */
final class TicketAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Ticket $ticket,
        private readonly User $assignee,
    ) {
        // Hold the queued job until the change it describes is committed.
        // Sending inside the transaction could put an email in someone's inbox
        // about a change that then rolled back, and that cannot be taken back.
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Someone is looking at your ticket: {$this->ticket->subject}")
            ->greeting("Hello {$this->ticket->requester->name},")
            ->line("{$this->assignee->name} has picked up your ticket \"{$this->ticket->subject}\".")
            ->line('You will hear again when the status changes.')
            ->action('Open the ticket', config('app.frontend_url').'/tickets/'.$this->ticket->getKey())
            ->line('Reference: '.$this->ticket->getKey());
    }
}
