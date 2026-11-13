<?php

declare(strict_types=1);

namespace App\Http\Requests\Events;

use App\Enums\EventAssignmentRole;
use App\Models\Event;
use App\Models\EventAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reglas de una asignación de usuario a evento (plan 011, CA-011-29 y -32),
 * reutilizadas por las Actions. Solo existen los roles `reviewer` y
 * `coordinator`; `speaker` y cualquier otro valor se rechazan. Que el usuario
 * sea integrante activo de la organización lo comprueba la Action.
 */
final class AssignUserToEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && Gate::allows('create', [EventAssignment::class, $event]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'uuid'],
            ...self::roleRules(),
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function roleRules(): array
    {
        return [
            'role' => ['required', Rule::enum(EventAssignmentRole::class)],
        ];
    }
}
