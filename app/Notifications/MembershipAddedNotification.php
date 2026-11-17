<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * `team.membership_added` (catálogo del plan 008, crítica): avisa a una
 * persona que ya tenía cuenta de que una organización la dio de alta.
 *
 * No lleva token ni enlace para fijar contraseña: la persona entra con la
 * cuenta que ya tiene. Recibe el nombre de la organización (no el modelo)
 * porque el aviso se arma en la cola, sin contexto de organización.
 */
final class MembershipAddedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const CATALOG_KEY = 'team.membership_added';

    public function __construct(private readonly string $organizationName) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('team.membership_added.subject', ['organization' => $this->organizationName]))
            ->greeting(__('team.membership_added.greeting'))
            ->line(__('team.membership_added.line', ['organization' => $this->organizationName]))
            ->action(__('team.membership_added.action'), route('filament.admin.auth.login'));
    }
}
