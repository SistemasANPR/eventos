<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * `auth.password_reset` (catálogo del plan 008, crítica): enlace firmado de
 * restablecimiento. La caducidad (60 minutos) y el uso único los impone el
 * token del broker de contraseñas (`config/auth.php`); la firma de la URL
 * impide alterar el correo o el token.
 */
final class PasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const CATALOG_KEY = 'auth.password_reset';

    public function __construct(public readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('access.password_reset.subject'))
            ->greeting(__('access.password_reset.greeting'))
            ->line(__('access.password_reset.line'))
            ->action(__('access.password_reset.action'), $this->url)
            ->line(__('access.password_reset.expiry', ['minutes' => config('auth.passwords.users.expire')]))
            ->line(__('access.password_reset.ignore'));
    }
}
