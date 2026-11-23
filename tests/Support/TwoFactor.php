<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FAQRCode\Google2FA;

/** Ayudas de prueba para la verificación en dos pasos (plan 014): activarla a un usuario y generar su código. */
final class TwoFactor
{
    /** Códigos de recuperación en claro con los que se siembra a quien se activa con `enable()`. */
    public const RECOVERY_CODES = ['AAAAAAAAAA-1111111111', 'BBBBBBBBBB-2222222222', 'CCCCCCCCCC-3333333333'];

    /** Deja al usuario con la 2FA confirmada y devuelve su secreto. */
    public static function enable(User $user): string
    {
        $secret = app(Google2FA::class)->generateSecretKey(16);

        $user->saveAppAuthenticationSecret($secret);
        $user->saveAppAuthenticationRecoveryCodes(array_map(Hash::make(...), self::RECOVERY_CODES));

        return $secret;
    }

    /** El código TOTP vigente para el secreto. */
    public static function code(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }
}
