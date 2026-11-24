<?php

declare(strict_types=1);

namespace App\Http\Requests\Submissions;

use App\Enums\SubmissionStatus;
use App\Models\Event;
use App\Models\Submission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reglas de una decisión masiva (plan 012, F2, H-012-2, CA-012-11): el estado de destino (aceptar, lista de
 * espera o rechazar), las propuestas elegidas (con máximo por operación), el comentario opcional para el
 * autor (su longitud y limpieza las vigila `AuthorComment`) y el filtro con que se hizo la selección, que
 * solo se guarda para la auditoría. La Action `BulkDecideSubmissions` las reutiliza y comprueba contra la
 * base que cada propuesta sea del evento y elegible.
 */
final class BulkDecideRequest extends FormRequest
{
    /** Propuestas por operación: lo que un comité decide de una vez sin que la transacción se vuelva inmanejable. */
    public const MAX_SUBMISSIONS = 200;

    /** Estados a los que se puede decidir en masa; la reversa es el lote (`RevertDecisionBatch`). */
    public const DECISIONS = [SubmissionStatus::Accepted, SubmissionStatus::Waitlisted, SubmissionStatus::Rejected];

    public function authorize(): bool
    {
        $event = $this->route('event');
        $first = $event instanceof Event ? Submission::query()->where('event_id', $event->id)->first() : null;

        return $first instanceof Submission && Gate::allows('decide', $first);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'to' => ['required', Rule::in(array_map(static fn (SubmissionStatus $status): string => $status->value, self::DECISIONS))],
            'submission_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_SUBMISSIONS],
            'submission_ids.*' => ['required', 'uuid', 'distinct'],
            'author_comment' => ['nullable', 'string'],
            'filter' => ['sometimes', 'array'],
            'filter.mode' => ['sometimes', Rule::in(['selection', 'filter'])],
            'filter.call_for_submissions_id' => ['nullable', 'uuid'],
            'filter.track_id' => ['nullable', 'uuid'],
            'filter.submission_type_id' => ['nullable', 'uuid'],
            'filter.status' => ['nullable', Rule::enum(SubmissionStatus::class)],
            'filter.from' => ['nullable', 'date_format:Y-m-d'],
            'filter.until' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
