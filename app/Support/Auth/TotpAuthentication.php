<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use App\Notifications\TwoFactorEnabledNotification;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\Actions\RegenerateAppAuthenticationRecoveryCodesAction;
use Filament\Auth\MultiFactor\App\Actions\SetUpAppAuthenticationAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Facades\Filament;
use SensitiveParameter;

/**
 * Segundo factor TOTP con códigos de recuperación (plan 014, DA-1), sobre la autenticación por
 * aplicación de Filament (sin criptografía propia). Se diferencia en dos cosas:
 *
 * - No ofrece «Desactivar»: nadie quita su propia 2FA (CA-014-4); la reinicia otro administrador
 *   o la plataforma con `ResetTwoFactor` y `ResetTwoFactorByPlatform`. El modelo también lo impide.
 * - Al confirmarla avisa por correo al titular (aviso de activación del catálogo).
 *
 * Los códigos de recuperación son de un solo uso, 8 por persona y se guardan con hash; no se
 * regeneran desde el panel (no hay pantalla de perfil): quien los pierde la reinicia con ayuda.
 */
final class TotpAuthentication extends AppAuthentication
{
    public static function configured(): static
    {
        return self::make()->recoverable()->regenerableRecoveryCodes(false);
    }

    /** @return array<Action> */
    public function getActions(): array
    {
        $user = Filament::auth()->user();

        return [
            SetUpAppAuthenticationAction::make($this)
                ->hidden(fn (): bool => $this->isEnabled($user)),
            RegenerateAppAuthenticationRecoveryCodesAction::make($this)
                ->visible(fn (): bool => $this->isEnabled($user) && $this->isRecoverable() && $this->canRegenerateRecoveryCodes()),
        ];
    }

    public function saveSecret(HasAppAuthentication $user, #[SensitiveParameter] ?string $secret): void
    {
        parent::saveSecret($user, $secret);

        if ($user instanceof User) {
            $user->notify(new TwoFactorEnabledNotification(now()->toImmutable()));
        }
    }
}
