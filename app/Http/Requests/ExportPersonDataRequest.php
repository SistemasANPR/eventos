<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PersonDataRequestReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos de la exportación de lo que la organización guarda de una persona (plan 014, CA-014-20): su correo
 * y el motivo (lista cerrada). La autorización la decide la Action con la Policy.
 */
final class ExportPersonDataRequest extends FormRequest
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
        ];
    }
}
