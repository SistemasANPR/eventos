<?php

declare(strict_types=1);

namespace App\Support\SubmissionSummary;

use App\Enums\SubmissionOrigin;
use App\Models\Event;
use App\Models\Submission;
use App\Models\SubmissionType;
use App\Models\Track;
use Carbon\CarbonImmutable;

/**
 * Resumen de las propuestas de un evento por eje, tipo y día (plan 009, H-009-4, CA-009-20 y CA-009-21).
 * Cuenta solo las de la convocatoria: los invitados (plan 018) nunca entran en sus conteos. Exige la
 * organización en contexto, así que el scope global acota la consulta a la organización; además se
 * filtra por el evento recibido. Los días se agrupan en la zona del evento, no en UTC. Sin propuestas
 * devuelve ceros y listas vacías (la pantalla muestra un estado vacío, no un error).
 */
final class SubmissionSummary
{
    /**
     * @return array{
     *     total: int,
     *     byTrack: list<array{label: string, count: int}>,
     *     byType: list<array{label: string, count: int}>,
     *     byDay: list<array{day: string, label: string, count: int}>
     * }
     */
    public static function for(Event $event): array
    {
        $base = fn () => Submission::query()
            ->where('event_id', $event->id)
            ->where('origin', SubmissionOrigin::Call);

        $total = $base()->count();

        if ($total === 0) {
            return ['total' => 0, 'byTrack' => [], 'byType' => [], 'byDay' => []];
        }

        $locale = app()->getLocale();

        /** @var array<string, int> $perTrack */
        $perTrack = $base()->selectRaw('track_id, count(*) as total')->groupBy('track_id')->pluck('total', 'track_id')->map(fn ($n): int => (int) $n)->all();
        /** @var array<string, int> $perType */
        $perType = $base()->selectRaw('submission_type_id, count(*) as total')->groupBy('submission_type_id')->pluck('total', 'submission_type_id')->map(fn ($n): int => (int) $n)->all();

        $tracks = Track::query()->whereIn('id', array_keys($perTrack))->get()
            ->mapWithKeys(fn (Track $track): array => [$track->id => (string) $track->getTranslation('name', $locale, true)]);
        $types = SubmissionType::query()->whereIn('id', array_keys($perType))->get()
            ->mapWithKeys(fn (SubmissionType $type): array => [$type->id => $type->label($locale)]);

        return [
            'total' => $total,
            'byTrack' => self::labelled($perTrack, $tracks->all()),
            'byType' => self::labelled($perType, $types->all()),
            'byDay' => self::perDay($base()->pluck('submitted_at'), $event->timezone, $locale),
        ];
    }

    /**
     * @param  array<string, int>  $counts  identificador => cantidad
     * @param  array<string, string>  $labels  identificador => etiqueta
     * @return list<array{label: string, count: int}>
     */
    private static function labelled(array $counts, array $labels): array
    {
        $rows = [];

        foreach ($counts as $id => $count) {
            $rows[] = ['label' => $labels[$id] ?? $id, 'count' => $count];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['count'], $a['label']] <=> [$a['count'], $b['label']]);

        return $rows;
    }

    /**
     * @param  iterable<mixed>  $instants  `submitted_at` en UTC
     * @return list<array{day: string, label: string, count: int}>
     */
    private static function perDay(iterable $instants, string $timezone, string $locale): array
    {
        $days = [];

        foreach ($instants as $instant) {
            if ($instant instanceof \DateTimeInterface) {
                $day = CarbonImmutable::instance($instant)->setTimezone($timezone)->format('Y-m-d');
                $days[$day] = ($days[$day] ?? 0) + 1;
            }
        }

        ksort($days);

        $rows = [];

        foreach ($days as $day => $count) {
            $rows[] = [
                'day' => $day,
                'label' => CarbonImmutable::createFromFormat('!Y-m-d', $day, $timezone)->locale($locale)->isoFormat('LL'),
                'count' => $count,
            ];
        }

        return $rows;
    }
}
