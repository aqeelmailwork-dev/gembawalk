<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountRejected extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(public ?string $reason = null)
    {
        //
    }

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
        $mail = (new MailMessage)
            ->subject('Your GembaWalk account request')
            ->greeting('Hello!')
            ->line('Thank you for your interest in GembaWalk.')
            ->line('Unfortunately, we are unable to approve your account request at this time.');

        if ($this->reason) {
            $mail->line('Reason: '.$this->reason);
        }

        return $mail;
    }
}
