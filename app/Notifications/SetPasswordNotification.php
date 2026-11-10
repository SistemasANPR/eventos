<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * `team.set_password` (catálogo del plan 008): invita a fijar la contraseña.
 *
 * El token se crea al enviar, no al encolar, para que los 60 minutos de
 * vigencia cuenten desde que el correo sale y no desde que se encola.
 */
final class SetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(CanResetPassword $notifiable): MailMessage
    {
        $token = Password::broker()->createToken($notifiable);
        $url = Filament::getPanel($notifiable instanceof User ? $notifiable->preferredPanelId() : 'admin')->getResetPasswordUrl($token, $notifiable);

        return (new MailMessage)
            ->subject(__('team.set_password.subject'))
            ->greeting(__('team.set_password.greeting'))
            ->line(__('team.set_password.line'))
            ->action(__('team.set_password.action'), $url);
    }
}
