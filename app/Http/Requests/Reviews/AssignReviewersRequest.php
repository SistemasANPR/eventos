<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use App\Enums\ReviewAssignmentScope;
use App\Models\Event;
use App\Models\ReviewAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reglas de una operación de asignación de evaluadores (plan 011, H-011-2): alcance `submission`
 * (propuestas elegidas), `track` (un eje) o `call` (una convocatoria), y los evaluadores. Las reutiliza la
 * Action `AssignReviewers`. Que los identificadores pertenezcan al evento, que cada evaluador tenga su
 * asignación de evento (CA-011-10) y que el alcance sea elegible lo comprueba la Action contra la base.
 */
final class AssignReviewersRequest extends FormRequest
{
    /** Evaluadores por operación: tope razonable para un comité; evita un correo masivo accidental. */
    public const MAX_REVIEWERS = 50;

    /** Propuestas por operación en el alcance `submission`. */
    public const MAX_SUBMISSIONS = 500;

    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && Gate::allows('create', [ReviewAssignment::class, $event]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::enum(ReviewAssignmentScope::class)],
            'reviewer_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_REVIEWERS],
            'reviewer_ids.*' => ['required', 'uuid', 'distinct'],
            'submission_ids' => ['required_if:scope,'.ReviewAssignmentScope::Submission->value, 'array', 'min:1', 'max:'.self::MAX_SUBMISSIONS],
            'submission_ids.*' => ['required', 'uuid', 'distinct'],
            'track_id' => ['required_if:scope,'.ReviewAssignmentScope::Track->value, 'uuid'],
            'call_id' => ['required_if:scope,'.ReviewAssignmentScope::Call->value, 'uuid'],
            // Solo por eje o convocatoria: guarda además la regla permanente de alcance (F3b, DA-6).
            'save_rule' => ['sometimes', 'boolean'],
        ];
    }
}
