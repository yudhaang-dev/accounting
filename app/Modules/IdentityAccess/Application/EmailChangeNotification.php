<?php

namespace App\Modules\IdentityAccess\Application;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email to the NEW address that carries the token confirming an address change (IA-FR-04). Sent on demand,
 * since the address does not belong to the account until it is confirmed.
 */
final class EmailChangeNotification extends Notification
{
    public function __construct(public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.url'), '/').'/account/confirm-email?token='.$this->token;

        return (new MailMessage)
            ->subject(__('account.email_change_subject'))
            ->line(__('account.email_change_line'))
            ->action(__('account.email_change_action'), $url);
    }
}
