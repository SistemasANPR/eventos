<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use App\Models\Event;
use App\Models\ReviewAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Reglas para quitar una regla permanente de alcance (plan 011, F3b, CA-011-43), reutilizadas por la Action
 * `RemoveScopeRule`. Que la regla sea del evento lo comprueba la Action contra la base.
 */
final class RemoveScopeRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && Gate::allows('create', [ReviewAssignment::class, $event]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'rule_id' => ['required', 'uuid'],
        ];
    }
}
