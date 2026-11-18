<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Models\User;
use App\Support\Html\RichTextSanitizer;
use App\Support\Program\PersonName;
use App\Support\Program\ProgramPromotion;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Pasa una propuesta aceptada al programa del evento (plan 021, DA-1 a DA-5, CA-021-7 a CA-021-9 y
 * CA-021-12): un ponente por participante (ligado a su `Contact`) y una actividad sin fecha, publicada
 * y enlazada a la propuesta (`event_activities.submission_id`), con todos esos ponentes.
 *
 * Está pensada para correr DENTRO de la transacción que acepta la propuesta (plan 012; aquí participa en
 * ella con un punto de guardado) y es IDEMPOTENTE: repetirla no crea nada nuevo. La autorización es de
 * quien decide la aceptación (plan 012): esta Action es su consecuencia y no exige `speakers.manage`;
 * por eso solo debe llamarse desde esa Action, nunca desde una ruta. `$actor` es opcional y solo
 * alimenta la auditoría. Se ejecuta en la organización de la propuesta y todo se acota a su evento.
 *
 * Reglas:
 * - Un participante ya es ponente si la propuesta lo creó antes (mismo contacto, aunque el organizador
 *   haya cambiado su correo) o si el evento tiene una ficha no eliminada con su correo (sin distinguir
 *   mayúsculas). Esa ficha se reutiliza: se rellenan solo los campos VACÍOS y nada editado se pisa.
 * - Un participante anterior al plan 021 (solo `full_name`) se parte con `PersonName::split`; si es una
 *   sola palabra el apellido queda como «-» (la base exige ambos) y el organizador lo corrige.
 * - Se republica solo lo que la reversa ocultó (`hidden_by_reversal_at`); lo despublicado a mano no.
 * - Una actividad que el organizador eliminó no se recrea ni se resucita (`activity` queda nula).
 * - Al volver a pasar, solo se enlazan a la actividad los ponentes que faltan si la actividad se creó o
 *   se republicó ahora, o si el ponente es nuevo: quitar a alguien a mano no se deshace en vano.
 */
final class PromoteSubmissionToProgram
{
    /** Apellido provisional de un nombre de una sola palabra. */
    private const PLACEHOLDER_LAST_NAME = '-';

    public function handle(?User $actor, Submission $submission): ProgramPromotion
    {
        $organization = Organization::query()->findOrFail($submission->organization_id);

        return CurrentOrganization::run($organization, fn (): ProgramPromotion => DB::transaction(function () use ($actor, $submission): ProgramPromotion {
            // Serializa los pasos del mismo evento: dos propuestas con el mismo correo no chocan en el índice único.
            Event::query()->whereKey($submission->event_id)->lockForUpdate()->firstOrFail();
            $submission = Submission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            $changed = false;
            $created = $reused = 0;
            /** @var array<string, Speaker> $speakers */
            $speakers = [];
            /** @var array<string, true> $newSpeakerIds */
            $newSpeakerIds = [];

            foreach ($submission->participants as $participant) {
                $speaker = $this->existingSpeaker($submission, $participant);

                if ($speaker === null) {
                    $speaker = $this->createSpeaker($submission, $participant);
                    $newSpeakerIds[$speaker->id] = true;
                    $created++;
                    $changed = true;
                } else {
                    $changed = $this->completeSpeaker($speaker, $participant) || $changed;
                    if (! isset($speakers[$speaker->id])) {
                        $reused++;
                    }
                }

                $speakers[$speaker->id] = $speaker;
            }

            [$activity, $activityCreated, $activityRepublished] = $this->activity($submission);
            $changed = $changed || $activityCreated || $activityRepublished;

            if ($activity !== null && ! $activity->trashed()) {
                $onlyNew = ! ($activityCreated || $activityRepublished);
                $linked = $this->linkSpeakers($activity, $submission, $onlyNew ? array_intersect_key($speakers, $newSpeakerIds) : $speakers);
                $changed = $changed || $linked > 0;
            }

            if ($changed) {
                activity()
                    ->causedBy($actor)
                    ->performedOn($submission)
                    ->event('submission_promoted')
                    ->withProperties([
                        'organization_id' => $submission->organization_id,
                        'event_id' => $submission->event_id,
                        'submission_id' => $submission->id,
                        'activity_id' => $activity?->id,
                        'speaker_ids' => array_keys($speakers),
                        'speakers_created' => $created,
                        'speakers_reused' => $reused,
                        'activity_created' => $activityCreated,
                    ])
                    ->log('submission_promoted');
            }

            return new ProgramPromotion(
                activity: $activity !== null && $activity->trashed() ? null : $activity,
                speakers: array_values($speakers),
                speakersCreated: $created,
                speakersReused: $reused,
                activityCreated: $activityCreated,
                changed: $changed,
            );
        }));
    }

    private function existingSpeaker(Submission $submission, SubmissionParticipant $participant): ?Speaker
    {
        $ownedByContact = Speaker::query()
            ->where('event_id', $submission->event_id)
            ->where('created_by_submission_id', $submission->id)
            ->where('contact_id', $participant->contact_id)
            ->first();

        return $ownedByContact ?? Speaker::query()
            ->where('event_id', $submission->event_id)
            ->whereRaw('lower(email) = ?', [mb_strtolower($participant->email)])
            ->first();
    }

    private function createSpeaker(Submission $submission, SubmissionParticipant $participant): Speaker
    {
        ['first' => $first, 'last' => $last] = $this->names($participant);

        $speaker = new Speaker;
        $speaker->event_id = $submission->event_id;
        $speaker->forceFill([
            'contact_id' => $participant->contact_id,
            'first_name' => $first,
            'last_name' => $last,
            'email' => $participant->email,
            'country' => $this->country($participant),
            'company' => $participant->company,
            'job_title' => $participant->job_title,
            'bio' => $this->bio($participant),
            'social_links' => [],
            'is_published' => true,
            'created_by_submission_id' => $submission->id,
        ])->save();

        return $speaker;
    }

    /**
     * Rellena SOLO lo vacío de una ficha existente y republica si la ocultó la reversa. Devuelve si cambió algo.
     */
    private function completeSpeaker(Speaker $speaker, SubmissionParticipant $participant): bool
    {
        $fill = [];

        foreach ([
            'contact_id' => $participant->contact_id,
            'country' => $this->country($participant),
            'company' => $participant->company,
            'job_title' => $participant->job_title,
            'bio' => $this->bio($participant),
        ] as $column => $value) {
            if ($value !== null && ($speaker->{$column} === null || $speaker->{$column} === '')) {
                $fill[$column] = $value;
            }
        }

        if ($speaker->email === null && ! $this->emailTaken($speaker, $participant->email)) {
            $fill['email'] = $participant->email;
        }

        if ($speaker->hidden_by_reversal_at !== null) {
            $fill['is_published'] = true;
            $fill['hidden_by_reversal_at'] = null;
        }

        if ($fill === []) {
            return false;
        }

        $speaker->forceFill($fill)->save();

        return true;
    }

    private function emailTaken(Speaker $speaker, string $email): bool
    {
        return Speaker::query()
            ->where('event_id', $speaker->event_id)
            ->whereKeyNot($speaker->id)
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->exists();
    }

    /**
     * La actividad de la propuesta: se crea sin fecha si no existe, se republica si la ocultó la reversa
     * y se deja como está si el organizador la eliminó.
     *
     * @return array{0: ?EventActivity, 1: bool, 2: bool} actividad, si se creó y si se republicó
     */
    private function activity(Submission $submission): array
    {
        $activity = EventActivity::query()->withTrashed()->where('submission_id', $submission->id)->first();

        if ($activity === null) {
            $activity = new EventActivity;
            $activity->event_id = $submission->event_id;
            $activity->forceFill([
                'submission_id' => $submission->id,
                'title' => mb_substr($submission->title, 0, 200),
                'is_published' => true,
            ])->save();

            return [$activity, true, false];
        }

        if (! $activity->trashed() && $activity->hidden_by_reversal_at !== null) {
            $activity->forceFill(['is_published' => true, 'hidden_by_reversal_at' => null])->save();

            return [$activity, false, true];
        }

        return [$activity, false, false];
    }

    /**
     * Enlaza a la actividad los ponentes que aún no están, al final de la lista.
     *
     * @param  array<string, Speaker>  $speakers
     * @return int cuántos se enlazaron
     */
    private function linkSpeakers(EventActivity $activity, Submission $submission, array $speakers): int
    {
        /** @var list<string> $linked */
        $linked = $activity->speakers()->pluck('speakers.id')->all();
        $position = $linked === [] ? 0 : (int) $activity->speakers()->max('activity_speaker.position') + 1;
        $count = 0;

        foreach ($speakers as $speaker) {
            if (in_array($speaker->id, $linked, true)) {
                continue;
            }

            $activity->speakers()->attach($speaker->id, [
                'organization_id' => $submission->organization_id,
                'event_id' => $submission->event_id,
                'position' => $position++,
            ]);
            $count++;
        }

        return $count;
    }

    /** @return array{first: string, last: string} */
    private function names(SubmissionParticipant $participant): array
    {
        $first = trim((string) $participant->first_name);
        $last = trim((string) $participant->last_name);

        if ($first === '' || $last === '') {
            ['first' => $first, 'last' => $last] = PersonName::split($participant->full_name);
        }

        return [
            'first' => mb_substr($first, 0, 100),
            'last' => mb_substr($last === '' ? self::PLACEHOLDER_LAST_NAME : $last, 0, 100),
        ];
    }

    private function country(SubmissionParticipant $participant): ?string
    {
        return $participant->country === '' ? null : strtoupper($participant->country);
    }

    /** La biografía de la propuesta es texto plano: se escapa y pasa a párrafos antes de sanearla (DA-16). */
    private function bio(SubmissionParticipant $participant): ?string
    {
        $text = trim((string) $participant->bio);

        if ($text === '') {
            return null;
        }

        $paragraphs = array_map(
            static fn (string $paragraph): string => '<p>'.nl2br(e(trim($paragraph)), false).'</p>',
            preg_split('/\R{2,}/u', $text) ?: [$text],
        );

        $clean = RichTextSanitizer::clean(implode('', $paragraphs));

        return $clean === '' ? null : $clean;
    }
}
