<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PersonDataRequestReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos de la anonimización de una persona (plan 014, CA-014-24): su correo, el motivo (lista cerrada: un
 * texto libre acabaría en la auditoría) y la confirmación explícita, que es volver a escribir el correo.
 * Sin confirmación, o con una distinta, no cambia ningún dato. La autorización la decide la Action con la Policy.
 */
final class AnonymizePersonRequest extends FormRequest
{
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
            'email' => ['required', 'string', 'email', 'max:254'],
            'reason' => ['required', 'string', Rule::enum(PersonDataRequestReason::class)],
            'confirmation' => ['required', 'string', 'same:email'],
        ];
    }
}
