<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\Submission;
use App\Models\User;
use App\Support\Program\ProgramReversal;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Oculta lo que `PromoteSubmissionToProgram` creó cuando una propuesta aceptada se revierte o se retira
 * (plan 021, DA-3, CA-021-10 y CA-021-11): la actividad de la propuesta y los ponentes que ella CREÓ
 * (`created_by_submission_id`) pasan a no publicados con `hidden_by_reversal_at`. Nada se borra y no se
 * envía correo. Un ponente que sigue en OTRA actividad publicada y no eliminada permanece visible; uno
 * que el organizador creó a mano o que ya existía no es de la propuesta y no se toca.
 *
 * Devuelve un `ProgramReversal` con los conteos y el texto del aviso para quien revierte, y audita
 * (ids y conteos, sin datos personales) solo si algo cambió: repetirla es idempotente. Como la Action
 * de promover, es consecuencia de la decisión sobre la propuesta (plan 012), que es quien autoriza;
 * `$actor` es opcional y solo alimenta la auditoría. Se ejecuta en la organización de la propuesta.
 */
final class HideProgramOfSubmission
{
    public function handle(?User $actor, Submission $submission): ProgramReversal
    {
        $organization = Organization::query()->findOrFail($submission->organization_id);

        return CurrentOrganization::run($organization, fn (): ProgramReversal => DB::transaction(function () use ($actor, $submission): ProgramReversal {
            Event::query()->whereKey($submission->event_id)->lockForUpdate()->firstOrFail();
            $submission = Submission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            $now = now();
            $activity = EventActivity::query()->where('submission_id', $submission->id)->first();
            $activityHidden = false;

            if ($activity !== null && $activity->is_published) {
                $activity->forceFill(['is_published' => false, 'hidden_by_reversal_at' => $now])->save();
                $activityHidden = true;
            }

            $hiddenIds = [];
            $kept = 0;

            $createdHere = Speaker::query()
                ->where('event_id', $submission->event_id)
                ->where('created_by_submission_id', $submission->id)
                ->where('is_published', true)
                ->get();

            foreach ($createdHere as $speaker) {
                if ($this->staysInAnotherPublishedActivity($speaker, $activity)) {
                    $kept++;

                    continue;
                }

                $speaker->forceFill(['is_published' => false, 'hidden_by_reversal_at' => $now])->save();
                $hiddenIds[] = $speaker->id;
            }

            $reversal = new ProgramReversal(
                activityTitle: $activity?->title,
                activityHidden: $activityHidden,
                speakersHidden: count($hiddenIds),
                speakersKept: $kept,
            );

            if ($reversal->changed) {
                activity()
                    ->causedBy($actor)
                    ->performedOn($submission)
                    ->event('submission_program_hidden')
                    ->withProperties([
                        'organization_id' => $submission->organization_id,
                        'event_id' => $submission->event_id,
                        'submission_id' => $submission->id,
                        'activity_id' => $activity?->id,
                        'activity_hidden' => $activityHidden,
                        'speaker_ids' => $hiddenIds,
                        'speakers_hidden' => count($hiddenIds),
                        'speakers_kept' => $kept,
                    ])
                    ->log('submission_program_hidden');
            }

            return $reversal;
        }));
    }

    /** Participa en otra actividad (distinta de la de la propuesta) publicada y no eliminada. */
    private function staysInAnotherPublishedActivity(Speaker $speaker, ?EventActivity $own): bool
    {
        $query = $speaker->activities()->where('event_activities.is_published', true);

        if ($own !== null) {
            $query->where('event_activities.id', '!=', $own->id);
        }

        return $query->exists();
    }
}
