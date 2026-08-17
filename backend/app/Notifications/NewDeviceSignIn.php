<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewDeviceSignIn extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $name,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New device sign-in on OMNEX — verify it\'s you')
            ->greeting('Hi '.$this->name.',')
            ->line('A new device (iPhone, Android phone or a passkey) just tried to sign in to your OMNEX account.')
            ->line('If this was you, enter this code to confirm the device:')
            ->line('**'.$this->code.'**')
            ->line('The code expires in 10 minutes and can only be used once.')
            ->line('If you did not try to sign in, change your password and revoke the session immediately.');
    }
}
