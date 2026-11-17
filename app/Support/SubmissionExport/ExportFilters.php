<?php

declare(strict_types=1);

namespace App\Support\SubmissionExport;

use App\Enums\SubmissionOrigin;
use App\Enums\SubmissionStatus;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Submission;
use App\Models\SubmissionType;
use App\Models\Track;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Filtros de una exportación (plan 009, DA-3). La exportación nunca confía en lo que el cliente
 * seleccionó: recibe los FILTROS, los valida contra el evento y recalcula la consulta desde la base.
 * Un valor ajeno, inexistente o mal formado produce el mismo error de validación genérico
 * (CA-009-4), sin revelar si existe. Se exige organización en contexto (los modelos pasan por su scope).
 */
final readonly class ExportFilters
{
    /** El origen por omisión es la convocatoria: la vista «Propuestas» no incluye invitados (plan 018). */
    private function __construct(
        public Event $event,
        public SubmissionOrigin $origin,
        public ?string $callId,
        public ?string $trackId,
        public ?string $typeId,
        public ?SubmissionStatus $status,
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $until,
    ) {}

    /**
     * @param  array<string, mixed>  $raw  `call_for_submissions_id`, `track_id`, `submission_type_id`, `status`, `origin`, `from`, `until`
     *
     * @throws ValidationException
     */
    public static function fromArray(array $raw, Event $event): self
    {
        $from = self::day($raw['from'] ?? null, $event->timezone);
        $until = self::day($raw['until'] ?? null, $event->timezone);

        if ($from !== null && $until !== null && $from->greaterThan($until)) {
            throw self::invalid();
        }

        return new self(
            $event,
            self::origin($raw['origin'] ?? null),
            self::owned($raw['call_for_submissions_id'] ?? null, CallForSubmissions::query(), $event),
            self::owned($raw['track_id'] ?? null, Track::query(), $event),
            self::owned($raw['submission_type_id'] ?? null, SubmissionType::query(), $event),
            self::status($raw['status'] ?? null),
            $from,
            $until,
        );
    }

    /**
     * Valores normalizados, sin nulos: es lo que queda en la auditoría y lo que viaja al job.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'origin' => $this->origin->value,
            'call_for_submissions_id' => $this->callId,
            'track_id' => $this->trackId,
            'submission_type_id' => $this->typeId,
            'status' => $this->status?->value,
            'from' => $this->from?->format('Y-m-d'),
            'until' => $this->until?->format('Y-m-d'),
        ], static fn (?string $value): bool => $value !== null);
    }

    /**
     * @return Builder<Submission>
     */
    public function query(): Builder
    {
        return Submission::query()
            ->where('event_id', $this->event->id)
            ->where('origin', $this->origin)
            ->when($this->callId, fn (Builder $q, string $id): Builder => $q->where('call_for_submissions_id', $id))
            ->when($this->trackId, fn (Builder $q, string $id): Builder => $q->where('track_id', $id))
            ->when($this->typeId, fn (Builder $q, string $id): Builder => $q->where('submission_type_id', $id))
            ->when($this->status, fn (Builder $q, SubmissionStatus $status): Builder => $q->where('status', $status))
            ->when($this->from, fn (Builder $q, CarbonImmutable $day): Builder => $q->where('submitted_at', '>=', $day->startOfDay()->utc()))
            ->when($this->until, fn (Builder $q, CarbonImmutable $day): Builder => $q->where('submitted_at', '<=', $day->endOfDay()->utc()));
    }

    /**
     * El identificador debe ser de un registro de ESTE evento (y, por el scope, de esta organización).
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private static function owned(mixed $id, Builder $query, Event $event): ?string
    {
        if (blank($id)) {
            return null;
        }

        if (! is_string($id) || ! $query->where('event_id', $event->id)->whereKey($id)->exists()) {
            throw self::invalid();
        }

        return $id;
    }

    private static function status(mixed $value): ?SubmissionStatus
    {
        if (blank($value)) {
            return null;
        }

        return (is_string($value) ? SubmissionStatus::tryFrom($value) : null) ?? throw self::invalid();
    }

    private static function origin(mixed $value): SubmissionOrigin
    {
        if (blank($value)) {
            return SubmissionOrigin::Call;
        }

        return (is_string($value) ? SubmissionOrigin::tryFrom($value) : null) ?? throw self::invalid();
    }

    private static function day(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            $day = is_string($value) ? CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone) : false;
        } catch (InvalidFormatException) {
            throw self::invalid();
        }

        // `createFromFormat` devuelve `false` con otro formato y «corrige» fechas imposibles (31 de febrero).
        if ($day === false || $day->format('Y-m-d') !== $value) {
            throw self::invalid();
        }

        return $day;
    }

    private static function invalid(): ValidationException
    {
        return ValidationException::withMessages(['filters' => __('submission_panel.filters.invalid')]);
    }
}
