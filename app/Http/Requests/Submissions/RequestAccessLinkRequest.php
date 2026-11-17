<?php

declare(strict_types=1);

namespace App\Http\Requests\Submissions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pedir un enlace de acceso (plan 006, H-006-3). Formulario público: la protección es el límite de
 * peticiones y Turnstile. El correo se normaliza antes de validar.
 */
final class RequestAccessLinkRequest extends FormRequest
{
    /** Mismo nombre que usa el formulario de envío (`StoreSubmissionController`). */
    public const TURNSTILE_FIELD = 'turnstile_token';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            self::TURNSTILE_FIELD => ['nullable', 'string', 'max:2048'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }
}
