<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Final public decision only; no inquiry contacts, internal reviewer metadata or legal verification claims. */
class ProviderApplicationDecision extends Notification
{
    public function __construct(public readonly string $status, public readonly ?string $publicReason) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('Property Provider application decision')
            ->line($this->status === 'approved' ? 'Your Property Provider application was approved.' : 'Your Property Provider application was rejected.');
        if ($this->publicReason !== null) {
            $mail->line($this->publicReason);
        }

        return $mail;
    }
}
