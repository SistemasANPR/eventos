<?php

declare(strict_types=1);

namespace App\Http\Requests\Submissions;

use App\Exceptions\Domain\AccessLinkInvalid;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirmar el enlace de acceso (plan 006, H-006-3). El POST no lleva cuerpo: el token viene de la ruta,
 * y solo se acota su longitud antes de tocar la base. Cualquier fallo de validación responde con el mismo
 * error genérico que un enlace inválido, sin revelar la causa (CA-006-36).
 */
final class ConsumeAccessLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'max:128']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['token' => $this->route('token')]);
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new AccessLinkInvalid;
    }

    /** El token es un secreto de la URL: solo se devuelve validado, nunca del cuerpo. */
    public function token(): string
    {
        return (string) $this->validated('token');
    }
}
