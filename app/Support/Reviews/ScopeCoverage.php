<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\ReviewAssignmentScope;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\SubmissionOrigin;
use App\Enums\SubmissionStatus;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Submission;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qué ejes y convocatorias de un evento tienen «cobertura incompleta» (plan 011, CA-011-48): un alcance lo está
 * cuando alguna de sus propuestas por evaluar tiene menos evaluadores activos que el mínimo de su rúbrica
 * (`ReviewCoverage`, el mismo cálculo de la columna de la lista, CA-011-6). Solo cuentan las propuestas
 * `origin = call` en `submitted` o `under_review`. Debe llamarse con la organización en contexto.
 */
final class ScopeCoverage
{
    /**
     * @return list<ScopeCoverageRow> solo los alcances incompletos, ejes primero y por nombre
     */
    public static function incompleteFor(Event $event): array
    {
        $coverage = ReviewCoverage::for($event);
        $rows = Submission::query()
            ->where('event_id', $event->id)
            ->where('origin', SubmissionOrigin::Call)
            ->whereIn('status', [SubmissionStatus::Submitted, SubmissionStatus::UnderReview])
            ->select(['track_id', 'call_for_submissions_id', 'submission_type_id'])
            ->withCount(['reviewAssignments as active_reviewers_count' => fn (Builder $assignments) => $assignments
                ->whereIn('status', ReviewAssignmentStatus::coverageValues())])
            ->toBase()
            ->get();

        /** @var array<string, array{total: int, incomplete: int}> $tracks */
        $tracks = [];
        /** @var array<string, array{total: int, incomplete: int}> $calls */
        $calls = [];

        foreach ($rows as $row) {
            $incomplete = $coverage->isIncomplete((string) $row->submission_type_id, (int) $row->active_reviewers_count) ? 1 : 0;

            self::tally($tracks, $row->track_id, $incomplete);
            self::tally($calls, $row->call_for_submissions_id, $incomplete);
        }

        return [
            ...self::rows(ReviewAssignmentScope::Track, $tracks, self::trackNames($event)),
            ...self::rows(ReviewAssignmentScope::Call, $calls, self::callNames($event)),
        ];
    }

    /** @param  array<string, array{total: int, incomplete: int}>  $bucket */
    private static function tally(array &$bucket, mixed $id, int $incomplete): void
    {
        if (! is_string($id)) {
            return;
        }

        $bucket[$id] ??= ['total' => 0, 'incomplete' => 0];
        $bucket[$id]['total']++;
        $bucket[$id]['incomplete'] += $incomplete;
    }

    /**
     * @param  array<string, array{total: int, incomplete: int}>  $counts
     * @param  array<string, string>  $names
     * @return list<ScopeCoverageRow>
     */
    private static function rows(ReviewAssignmentScope $scope, array $counts, array $names): array
    {
        $rows = [];

        foreach ($counts as $id => $count) {
            if ($count['incomplete'] > 0) {
                $rows[] = new ScopeCoverageRow($scope, $id, $names[$id] ?? $id, $count['incomplete'], $count['total']);
            }
        }

        usort($rows, static fn (ScopeCoverageRow $a, ScopeCoverageRow $b): int => strcmp($a->name, $b->name));

        return $rows;
    }

    /** @return array<string, string> */
    private static function trackNames(Event $event): array
    {
        return Track::query()->where('event_id', $event->id)->get()
            ->mapWithKeys(fn (Track $track): array => [$track->id => $track->label(app()->getLocale())])
            ->all();
    }

    /** @return array<string, string> */
    private static function callNames(Event $event): array
    {
        return CallForSubmissions::query()->where('event_id', $event->id)->get()
            ->mapWithKeys(fn (CallForSubmissions $call): array => [$call->id => (string) $call->getTranslation('title', app()->getLocale(), true)])
            ->all();
    }
}
