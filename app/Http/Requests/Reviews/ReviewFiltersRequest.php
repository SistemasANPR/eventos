<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtros de las páginas de resultados y de avance (plan 011, F6, CA-011-57), reutilizados por las Actions
 * `ComputeSubmissionResults` y `ComputeReviewerProgress`. Solo valida la forma; que el eje, la convocatoria
 * o el evaluador sean del evento lo comprueba `ReviewEventFilters` contra la base.
 */
final class ReviewFiltersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'track_id' => ['nullable', 'uuid'],
            'call_id' => ['nullable', 'uuid'],
            'reviewer_id' => ['nullable', 'uuid'],
        ];
    }
}
