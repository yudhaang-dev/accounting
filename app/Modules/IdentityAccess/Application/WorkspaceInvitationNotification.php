<?php

namespace App\Modules\IdentityAccess\Application;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email that carries the invitation token (IA-FR-01). Sent on demand to the invited address, since
 * the person may not have an account yet.
 */
final class WorkspaceInvitationNotification extends Notification
{
    public function __construct(
        public readonly string $workspaceName,
        public readonly string $token,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.url'), '/').'/invitations/accept?token='.$this->token;

        return (new MailMessage)
            ->subject(__('memberships.invitation_subject', ['workspace' => $this->workspaceName]))
            ->line(__('memberships.invitation_line', ['workspace' => $this->workspaceName]))
            ->action(__('memberships.invitation_action'), $url);
    }
}
