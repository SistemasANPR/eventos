<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\EventAssignmentRole;
use App\Http\Requests\Reviews\ReviewFiltersRequest;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\Track;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Filtros validados de las páginas de resultados y de avance (plan 011, F6): eje, convocatoria y evaluador
 * del evento. Un identificador que no es del evento (de otro evento, de otra organización o inexistente)
 * produce el mismo error de validación genérico: no revela cuál existe. Exige la organización en contexto.
 */
final readonly class ReviewEventFilters
{
    public function __construct(
        public ?string $trackId = null,
        public ?string $callId = null,
        public ?string $reviewerId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  `track_id`, `call_id` y `reviewer_id`, todos opcionales
     *
     * @throws ValidationException si la forma es inválida o algún identificador no es del evento
     */
    public static function validated(Event $event, array $input): self
    {
        $data = Validator::make($input, (new ReviewFiltersRequest)->rules())->validate();
        $trackId = self::filled($data['track_id'] ?? null);
        $callId = self::filled($data['call_id'] ?? null);
        $reviewerId = self::filled($data['reviewer_id'] ?? null);

        $valid = ($trackId === null || Track::query()->where('event_id', $event->id)->whereKey($trackId)->exists())
            && ($callId === null || CallForSubmissions::query()->where('event_id', $event->id)->whereKey($callId)->exists())
            && ($reviewerId === null || EventAssignment::query()
                ->where('event_id', $event->id)
                ->where('role', EventAssignmentRole::Reviewer)
                ->where('user_id', $reviewerId)
                ->exists());

        if (! $valid) {
            throw ValidationException::withMessages(['filters' => __('review_results.invalid_filter')]);
        }

        return new self($trackId, $callId, $reviewerId);
    }

    private static function filled(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
