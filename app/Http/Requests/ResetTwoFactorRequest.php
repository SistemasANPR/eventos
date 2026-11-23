<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos del reinicio de la 2FA por la plataforma (plan 014, CA-014-5): la verificación fuera de
 * banda es obligatoria, con el canal usado (distinto del correo de la cuenta) y una nota de
 * quién confirmó y a qué contacto previamente registrado. El reinicio por otro administrador de la
 * organización no lleva datos. La autorización la deciden las Actions con la Policy.
 */
final class ResetTwoFactorRequest extends FormRequest
{
    /** Canales fuera de banda aceptados: nunca el correo de la propia cuenta. */
    public const CHANNELS = ['phone_call', 'message', 'in_person'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return self::fieldRules();
    }

    /** @return array<string, list<mixed>> */
    public static function fieldRules(): array
    {
        return [
            'verification_channel' => ['required', 'string', Rule::in(self::CHANNELS)],
            'verification_note' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
