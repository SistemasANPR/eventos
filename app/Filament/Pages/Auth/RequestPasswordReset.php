<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Notifications\PasswordResetNotification;
use App\Support\Auth\AccessThrottle;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as FilamentRequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Filament\Support\Components\Attributes\Exposed;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Timebox;
use SensitiveParameter;

/**
 * Solicitud de restablecimiento con respuesta idéntica exista o no el correo
 * (CA-001-28) y límite de 5 solicitudes por 15 minutos por correo y por IP
 * (CA-001-29).
 *
 * Filament avisa «no encontramos ese usuario» cuando el correo no existe, lo
 * que permite enumerar cuentas; aquí el resultado del broker se ignora y la
 * llamada se iguala en duración con `Timebox`.
 */
class RequestPasswordReset extends FilamentRequestPasswordReset
{
    #[Exposed]
    public function request(): void
    {
        $data = $this->form->getState();
        $email = AccessThrottle::normalizeEmail(is_string($data['email'] ?? null) ? $data['email'] : '');
        $ip = (string) request()->ip();

        app(AccessThrottle::class)->hitOrFail(
            'password_reset_throttled',
            ['reset:email:'.AccessThrottle::emailHash($email), 'reset:ip:'.hash('sha256', $ip)],
            ['ip' => $ip, 'email_hash' => AccessThrottle::emailHash($email)],
        );

        app(Timebox::class)->call(function () use ($email): void {
            Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
                ['email' => $email],
                function (CanResetPassword $user, #[SensitiveParameter] string $token): void {
                    if ($user instanceof FilamentUser && ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
                        return;
                    }

                    // `notify()` existe en el modelo de usuario (trait Notifiable).
                    $user->notify(new PasswordResetNotification(Filament::getResetPasswordUrl($token, $user)));
                },
            );
        }, (int) config('auth.timebox_duration', 200_000));

        $this->getSentNotification(Password::RESET_LINK_SENT)?->send();
        $this->form->fill();
    }

    protected function getSentNotification(string $status): ?Notification
    {
        return Notification::make()
            ->title(__('access.reset_request.sent.title'))
            ->body(__('access.reset_request.sent.body'))
            ->success();
    }
}
