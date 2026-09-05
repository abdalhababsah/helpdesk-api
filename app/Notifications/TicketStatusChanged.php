<?php

namespace App\Notifications;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the person who raised a ticket that it moved.
 *
 * Queued, and held until the surrounding transaction commits. Sending inside
 * the transaction would put an email in someone's inbox describing a change
 * that then rolled back, and unlike a log entry that cannot be taken back.
 */
final class TicketStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Ticket $ticket,
        private readonly TicketStatus $from,
        private readonly TicketStatus $to,
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
        $message = (new MailMessage)
            ->subject("Your ticket is now {$this->label($this->to)}: {$this->ticket->subject}")
            ->greeting("Hello {$this->ticket->requester->name},")
            ->line("Your ticket \"{$this->ticket->subject}\" has moved from {$this->label($this->from)} to {$this->label($this->to)}.");

        if ($this->to === TicketStatus::Resolved) {
            $message->line('If this is not sorted, reply on the ticket and it will be picked up again.');
        }

        if ($this->to === TicketStatus::Closed) {
            $message->line('This ticket is now closed and will not take further replies.');
        }

        return $message
            ->action('Open the ticket', config('app.frontend_url').'/tickets/'.$this->ticket->getKey())
            ->line('Reference: '.$this->ticket->getKey());
    }

    private function label(TicketStatus $status): string
    {
        return str_replace('_', ' ', $status->value);
    }
}
