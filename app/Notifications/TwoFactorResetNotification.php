<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * `auth.two_factor_reset` (catálogo del plan 008, crítica): avisa al titular de que otra persona
 * reinició su verificación en dos pasos (plan 014, CA-014-4 y -5). Solo lleva la fecha (UTC); no
 * nombra a quien la reinició ni lleva secretos.
 */
final class TwoFactorResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const CATALOG_KEY = 'auth.two_factor_reset';

    public function __construct(public readonly CarbonImmutable $occurredAt)
    {
        // El reinicio ocurre dentro de una transacción: el aviso sale solo si se confirma.
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
            ->subject(__('access.two_factor_reset.subject'))
            ->greeting(__('access.two_factor_reset.greeting'))
            ->line(__('access.two_factor_reset.line'))
            ->line(__('access.two_factor_reset.when', ['date' => $this->occurredAt->utc()->format('Y-m-d H:i')]))
            ->line(__('access.two_factor_reset.next'))
            ->line(__('access.two_factor_reset.ignore'));
    }
}
