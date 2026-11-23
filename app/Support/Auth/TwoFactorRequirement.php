<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;

/**
 * Contrato de la verificación en dos pasos obligatoria (plan 014, H-014-1) para los demás
 * planes. Dos preguntas distintas, que no se confunden:
 *
 * - `isEnforced()`: ¿está activa la exigencia en este entorno? (`config/two_factor.php`). Si es
 *   falso, ningún panel la pide; el plan 011 rechaza entonces invitar a evaluadores externos
 *   (H-011-8, CA-011-34).
 * - `User::requiresTwoFactor()` y `User::hasConfirmedTwoFactor()`: ¿a esta persona se le exige y
 *   ya la configuró?
 *
 * Quien decida acceso a datos de autores usa `isSatisfiedBy($user)`: exige la 2FA confirmada
 * solo cuando la exigencia está activa y a la persona le aplica.
 */
final class TwoFactorRequirement
{
    public static function isEnforced(): bool
    {
        return (bool) config('two_factor.required');
    }

    /** La exigencia no aplica (apagada o rol exento) o la persona ya confirmó su segundo factor. */
    public static function isSatisfiedBy(User $user): bool
    {
        return ! self::isEnforced() || ! $user->requiresTwoFactor() || $user->hasConfirmedTwoFactor();
    }
}
