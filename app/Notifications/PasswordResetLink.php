<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Carries the one-time link. Held until the token row is committed, or the
 * link could arrive before the row it points at exists.
 */
final class PasswordResetLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $link,
        private readonly int $ttlMinutes,
    ) {
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
            ->subject('Reset your helpdesk password')
            ->greeting("Hello {$notifiable->name},")
            ->line('Use the button below to choose a new password. It works once and expires in '.$this->ttlMinutes.' minutes.')
            ->action('Choose a new password', $this->link)
            ->line('If you did not ask for this, ignore this email. Your password stays as it is.');
    }
}
