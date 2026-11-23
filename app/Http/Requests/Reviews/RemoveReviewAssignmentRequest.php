<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use App\Models\Event;
use App\Models\ReviewAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Reglas para quitar una asignación de evaluador (plan 011, CA-011-45 y 46), reutilizadas por la Action
 * `RemoveReviewAssignment`. Que la asignación sea del evento lo comprueba la Action contra la base.
 */
final class RemoveReviewAssignmentRequest extends FormRequest
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
            'assignment_id' => ['required', 'uuid'],
        ];
    }
}
