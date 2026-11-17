<?php

declare(strict_types=1);

namespace App\Notifications;

use Filament\Facades\Filament;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * `auth.password_reset` (catálogo del plan 008, crítica): enlace firmado de
 * restablecimiento. La caducidad (60 minutos) y el uso único los impone el
 * token del broker de contraseñas (`config/auth.php`); la firma de la URL
 * impide alterar el correo o el token.
 *
 * No guarda el token ni la URL: se crean al enviar (`toMail`), de modo que el
 * secreto nunca queda en la tabla `jobs` mientras el correo espera en cola.
 */
final class PasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const CATALOG_KEY = 'auth.password_reset';

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(CanResetPassword $notifiable): MailMessage
    {
        // El restablecimiento vive en el panel del acceso único (plan 017, DA-5).
        $panel = Filament::getPanel('admin');
        $token = Password::broker($panel->getAuthPasswordBroker())->createToken($notifiable);
        $url = $panel->getResetPasswordUrl($token, $notifiable);

        return (new MailMessage)
            ->subject(__('access.password_reset.subject'))
            ->greeting(__('access.password_reset.greeting'))
            ->line(__('access.password_reset.line'))
            ->action(__('access.password_reset.action'), $url)
            ->line(__('access.password_reset.expiry', ['minutes' => config('auth.passwords.users.expire')]))
            ->line(__('access.password_reset.ignore'));
    }
}
