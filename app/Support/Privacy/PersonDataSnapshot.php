<?php

declare(strict_types=1);

namespace App\Support\Privacy;

use App\Models\Activity;
use App\Models\Contact;
use App\Models\KnownDevice;
use App\Models\MailDispatch;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewAssignment;
use App\Models\Speaker;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\SubmissionParticipant;
use App\Models\SubmissionStatusChange;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Lo que la organización en contexto guarda de una persona, listo para entregárselo (plan 014, CA-014-20).
 * Solo datos de ELLA: nunca los de otras personas de sus propuestas (coautores), ni secretos, ni hashes, ni
 * rutas de disco, ni el motivo interno de una decisión (no se le envía al autor) ni la identidad de quien la evaluó.
 *
 * Las evaluaciones de sus propuestas se incluyen sin identidad del evaluador (decisión DA-7 del plan 014, a
 * confirmar por el responsable). Exige la organización en contexto.
 */
final class PersonDataSnapshot
{
    /** Tope de entradas de auditoría que se incluyen. */
    private const AUDIT_LIMIT = 500;

    /** @return array<string, mixed> */
    public static function build(PersonRecords $records): array
    {
        $user = $records->userId === null ? null : User::query()->find($records->userId);

        return [
            'generated_at' => self::iso(now()),
            'subject' => ['email' => $records->email],
            'contacts' => self::contacts($records),
            'submissions' => self::submissions($records),
            'speakers' => self::speakers($records),
            'account' => $user === null ? null : self::account($user),
            'reviews_made' => $user === null ? [] : self::reviewsMade($user),
            'emails_sent' => self::emailsSent($records),
            'audit' => self::audit($records),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function contacts(PersonRecords $records): array
    {
        return Contact::query()->whereKey($records->contactIds)->whereNull('anonymized_at')->get()
            ->map(fn (Contact $contact): array => [
                'full_name' => $contact->full_name,
                'email' => $contact->email,
                'country' => $contact->country,
                'created_at' => self::iso($contact->created_at),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private static function submissions(PersonRecords $records): array
    {
        $participants = SubmissionParticipant::query()->whereKey($records->participantIds)->whereNull('anonymized_at')->get()->groupBy('submission_id');
        $files = SubmissionFile::withTrashed()->whereIn('submission_id', $records->primarySubmissionIds)->whereNull('anonymized_at')->get()->groupBy('submission_id');
        $changes = SubmissionStatusChange::query()->whereIn('submission_id', $records->submissionIds)->orderBy('created_at')->get()->groupBy('submission_id');
        $answers = ReviewAnswer::query()
            ->whereIn('review_id', Review::withTrashed()
                ->whereNotNull('submitted_at')
                ->whereIn('review_assignment_id', ReviewAssignment::query()->whereIn('submission_id', $records->submissionIds)->select('id'))
                ->select('id'))
            ->with('review.assignment')
            ->get()
            ->groupBy(fn (ReviewAnswer $answer): string => (string) $answer->review->assignment->submission_id);

        return Submission::withTrashed()->whereIn('id', $records->submissionIds)->orderBy('submitted_at')->get()
            ->map(fn (Submission $submission): array => [
                'id' => $submission->id,
                'event_id' => $submission->event_id,
                'title' => $submission->title,
                'status' => $submission->status->value,
                'submitted_at' => self::iso($submission->submitted_at),
                'answers' => $submission->answers,
                'author_comment' => $submission->author_comment,
                'participant' => ($participants[$submission->id] ?? collect())->map(fn (SubmissionParticipant $participant): array => [
                    'position' => $participant->position,
                    'is_primary' => $participant->is_primary,
                    'first_name' => $participant->first_name,
                    'last_name' => $participant->last_name,
                    'full_name' => $participant->full_name,
                    'email' => $participant->email,
                    'country' => $participant->country,
                    'job_title' => $participant->job_title,
                    'company' => $participant->company,
                    'bio' => $participant->bio,
                ])->values()->all(),
                'files' => ($files[$submission->id] ?? collect())->map(fn (SubmissionFile $file): array => [
                    'original_name' => $file->original_name,
                    'mime_type' => $file->mime_type,
                    'size_bytes' => $file->size_bytes,
                    'uploaded_at' => self::iso($file->uploaded_at),
                ])->values()->all(),
                'status_changes' => ($changes[$submission->id] ?? collect())->map(fn (SubmissionStatusChange $change): array => [
                    'from' => $change->from_status->value,
                    'to' => $change->to_status->value,
                    'at' => self::iso($change->created_at),
                    'author_comment' => $change->author_comment,
                ])->values()->all(),
                'evaluations' => ($answers[$submission->id] ?? collect())->map(fn (ReviewAnswer $answer): array => [
                    'criterion_id' => $answer->rubric_criterion_id,
                    'value_int' => $answer->value_int,
                    'value_text' => $answer->value_text,
                ])->values()->all(),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private static function speakers(PersonRecords $records): array
    {
        return Speaker::withTrashed()->whereIn('id', $records->speakerIds)->whereNull('anonymized_at')->get()
            ->map(fn (Speaker $speaker): array => [
                'event_id' => $speaker->event_id,
                'first_name' => $speaker->first_name,
                'last_name' => $speaker->last_name,
                'email' => $speaker->email,
                'country' => $speaker->country,
                'company' => $speaker->company,
                'job_title' => $speaker->job_title,
                'tagline' => $speaker->tagline,
                'bio' => $speaker->bio,
                'has_photo' => $speaker->photo_path !== null,
                'social_links' => $speaker->social_links,
                'is_published' => $speaker->is_published,
            ])->values()->all();
    }

    /** @return array<string, mixed> */
    private static function account(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values()->all(),
            'two_factor_enabled' => $user->hasConfirmedTwoFactor(),
            'known_devices' => KnownDevice::query()->where('user_id', $user->id)->count(),
            'created_at' => self::iso($user->created_at),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function reviewsMade(User $user): array
    {
        $assignments = ReviewAssignment::query()->where('reviewer_id', $user->id)->get();
        $reviews = Review::withTrashed()->whereIn('review_assignment_id', $assignments->pluck('id'))->with('answers')->get()->groupBy('review_assignment_id');

        return $assignments
            ->map(fn (ReviewAssignment $assignment): array => [
                'submission_id' => $assignment->submission_id,
                'status' => $assignment->status->value,
                'assigned_at' => self::iso($assignment->assigned_at),
                'answers' => ($reviews[$assignment->id] ?? collect())->flatMap(fn (Review $review) => $review->answers)->map(fn (ReviewAnswer $answer): array => [
                    'criterion_id' => $answer->rubric_criterion_id,
                    'value_int' => $answer->value_int,
                    'value_text' => $answer->value_text,
                ])->values()->all(),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private static function emailsSent(PersonRecords $records): array
    {
        $hashes = array_map(PersonIdentity::dispatchHash(...), $records->emails);

        return MailDispatch::query()->whereIn('recipient_hash', $hashes)->orderBy('created_at')->get()
            ->map(fn (MailDispatch $dispatch): array => [
                'notification' => $dispatch->notification_key,
                'status' => $dispatch->status->value,
                'sent_at' => self::iso($dispatch->sent_at),
                'created_at' => self::iso($dispatch->created_at),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private static function audit(PersonRecords $records): array
    {
        $ids = array_values(array_filter([...$records->contactIds, ...$records->participantIds, ...$records->speakerIds, $records->userId]));

        return Activity::query()
            ->where('organization_id', CurrentOrganization::id())
            ->where(fn ($query) => $query->whereIn('subject_id', $ids)->orWhereIn('causer_id', $ids))
            ->orderBy('id')
            ->limit(self::AUDIT_LIMIT)
            ->get()
            ->map(fn (Activity $entry): array => [
                'event' => $entry->event,
                'log' => $entry->log_name,
                'at' => self::iso($entry->created_at),
            ])->values()->all();
    }

    private static function iso(?DateTimeInterface $date): ?string
    {
        return $date === null ? null : CarbonImmutable::instance($date)->utc()->toIso8601String();
    }
}
