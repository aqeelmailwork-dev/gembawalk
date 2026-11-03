<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountApproved extends Notification
{
    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your GembaWalk account is approved')
            ->greeting('Hello!')
            ->line('Great news — your GembaWalk account has been approved.')
            ->line('You can now log in and start using GembaWalk.')
            ->action('Log in', route('login'));
    }
}
