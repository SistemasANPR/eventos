<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use App\Enums\SubmissionOrigin;
use App\Enums\SubmissionStatus;
use App\Models\CallForSubmissions;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Builder;

/**
 * Las propuestas de una convocatoria con un resultado decidido que todavía no se avisó al autor (plan 012,
 * F2, DA-2). Es la única definición de «pendiente de publicar»: la usan `PublishResults` y la confirmación
 * que muestra cuántas se avisarán. Requiere el contexto de organización.
 */
final class PendingResults
{
    /** @return Builder<Submission> */
    public static function query(CallForSubmissions $call): Builder
    {
        return Submission::query()
            ->where('call_for_submissions_id', $call->id)
            ->where('origin', SubmissionOrigin::Call)
            ->whereIn('status', [SubmissionStatus::Accepted, SubmissionStatus::Waitlisted, SubmissionStatus::Rejected])
            ->whereNull('results_published_at');
    }

    /** @return array<string, int> pendientes por estado (`accepted`, `waitlisted`, `rejected`) */
    public static function countsByStatus(CallForSubmissions $call): array
    {
        return self::query($call)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->toBase()
            ->pluck('total', 'status')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
    }
}
