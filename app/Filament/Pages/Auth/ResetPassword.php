<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword as FilamentResetPassword;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Components\Attributes\Exposed;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Restablecimiento de contraseña con un mensaje genérico para cualquier fallo
 * del enlace (usado, caducado, falsificado o de un correo inexistente), de modo
 * que no revela si la cuenta existe (CA-001-30). La longitud mínima y la lista
 * de contraseñas expuestas vienen de `Password::defaults()` (CA-001-31), que
 * el formulario de Filament ya aplica.
 */
class ResetPassword extends FilamentResetPassword
{
    #[Exposed]
    public function resetPassword(): ?PasswordResetResponse
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        if ($this->isResetPasswordRateLimited($this->email)) {
            return null;
        }

        $data = $this->form->getState();
        $data['email'] = $this->email;
        $data['token'] = $this->token;

        $hasPanelAccess = true;

        $status = Password::broker(Filament::getAuthPasswordBroker())->reset(
            $this->getCredentialsFromFormData($data),
            function (CanResetPassword|Model|Authenticatable $user) use ($data, &$hasPanelAccess): void {
                if ($user instanceof User && ! $user->canResetPassword(Filament::getCurrentOrDefaultPanel())) {
                    $hasPanelAccess = false;

                    return;
                }

                $user->forceFill([
                    $user->getAuthPasswordName() => Hash::make($data['password']),
                    $user->getRememberTokenName() => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status === Password::PASSWORD_RESET && $hasPanelAccess) {
            Notification::make()->title(__($status))->success()->send();

            return app(PasswordResetResponse::class);
        }

        Notification::make()->title(__('access.reset_invalid'))->danger()->send();

        return null;
    }
}
