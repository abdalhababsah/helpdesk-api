<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the person their password changed, so a change they did not make is noticed. */
final class PasswordChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
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
            ->subject('Your helpdesk password was changed')
            ->greeting("Hello {$notifiable->name},")
            ->line('Your password was changed just now and every other device has been signed out.')
            ->action('Sign in', config('app.frontend_url').'/login')
            ->line('If this was not you, ask an administrator to send you a new reset link straight away.');
    }
}
