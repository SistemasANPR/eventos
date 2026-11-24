<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use App\Models\Review;
use App\Models\ReviewAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Reglas para declarar un conflicto de interés (plan 011, CA-011-17), reutilizadas por la Action
 * `DeclareConflictOfInterest`. La declaración es una afirmación personal del evaluador sobre una asignación
 * suya: exige confirmación explícita (nunca basta con invocar la ruta). Que la asignación sea suya y siga
 * vigente lo decide `ReviewPolicy::declareConflict`.
 */
final class DeclareConflictRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof ReviewAssignment && Gate::allows('declareConflict', [Review::class, $assignment]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'accepted'],
        ];
    }
}
