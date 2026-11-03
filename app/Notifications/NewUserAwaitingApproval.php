<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewUserAwaitingApproval extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(public User $user)
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
        return (new MailMessage)
            ->subject('New user awaiting approval')
            ->greeting('Hello!')
            ->line('A new user has signed up and is waiting for an administrator to approve their account.')
            ->line('Name: '.$this->user->name)
            ->line('Email: '.$this->user->email)
            ->action('Review users', url('/admin/users'));
    }
}
