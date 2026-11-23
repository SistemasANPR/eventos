<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * `auth.two_factor_enabled` (catálogo del plan 008, crítica): avisa al titular de que se activó la
 * verificación en dos pasos en su cuenta (plan 014). Solo lleva la fecha (UTC); nunca el secreto
 * ni los códigos de recuperación.
 */
final class TwoFactorEnabledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const CATALOG_KEY = 'auth.two_factor_enabled';

    public function __construct(public readonly CarbonImmutable $occurredAt)
    {
        // La activación ocurre dentro de una transacción: el aviso sale solo si se confirma.
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
            ->subject(__('access.two_factor_enabled.subject'))
            ->greeting(__('access.two_factor_enabled.greeting'))
            ->line(__('access.two_factor_enabled.line'))
            ->line(__('access.two_factor_enabled.when', ['date' => $this->occurredAt->utc()->format('Y-m-d H:i')]))
            ->line(__('access.two_factor_enabled.ignore'));
    }
}
