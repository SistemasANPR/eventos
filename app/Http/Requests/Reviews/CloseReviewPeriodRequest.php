<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use App\Models\Event;
use App\Models\Review;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Reglas para cerrar o reabrir el periodo de evaluación de un evento (plan 011, F6, CA-011-26 y 27),
 * reutilizadas por las Actions `CloseReviewPeriod` y `ReopenReviewPeriod`. El motivo es opcional y solo
 * viaja a la auditoría.
 */
final class CloseReviewPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && Gate::allows('closePeriod', [Review::class, $event]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
