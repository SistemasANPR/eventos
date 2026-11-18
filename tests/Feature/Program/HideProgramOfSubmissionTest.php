<?php

declare(strict_types=1);

use App\Actions\Program\HideProgramOfSubmission;
use App\Actions\Program\PromoteSubmissionToProgram;
use App\Enums\SubmissionStatus;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity as AuditLog;

uses(RefreshDatabase::class);

/*
 * Plan 021, F5: reversa de una propuesta aceptada (DA-3, CA-021-10 y CA-021-11). Se oculta, no se borra.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array{0: Event, 1: Submission} evento con una propuesta aceptada de dos participantes ya promovida */
function promotedSubmission(?Event $event = null, string $prefix = 'a'): array
{
    $event ??= Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $submission = CurrentOrganization::run($event->organization, function () use ($event, $prefix): Submission {
        $record = Submission::factory()->create(['event_id' => $event->id, 'status' => SubmissionStatus::Accepted, 'title' => "Propuesta {$prefix}"]);
        foreach ([1, 2] as $position) {
            SubmissionParticipant::factory()->create([
                'submission_id' => $record->id,
                'position' => $position,
                'is_primary' => $position === 1,
                'email' => "{$prefix}{$position}@example.com",
            ]);
        }

        return $record;
    });

    app(PromoteSubmissionToProgram::class)->handle(null, $submission);

    return [$event, $submission];
}

function reversalActivity(Event $event, Submission $submission): EventActivity
{
    return CurrentOrganization::run($event->organization, fn (): EventActivity => EventActivity::query()->where('submission_id', $submission->id)->firstOrFail());
}

function reversalSpeaker(Event $event, string $email): Speaker
{
    return CurrentOrganization::run($event->organization, fn (): Speaker => Speaker::query()->where('email', $email)->firstOrFail());
}

it('CA-021-10: oculta la actividad y a sus ponentes con la marca de reversa y no borra nada', function (): void {
    [$event, $submission] = promotedSubmission();

    $result = app(HideProgramOfSubmission::class)->handle(null, $submission);

    $activity = reversalActivity($event, $submission);
    expect($activity->is_published)->toBeFalse()
        ->and($activity->hidden_by_reversal_at)->not->toBeNull()
        ->and($activity->deleted_at ?? null)->toBeNull();

    foreach (['a1@example.com', 'a2@example.com'] as $email) {
        $speaker = reversalSpeaker($event, $email);
        expect($speaker->is_published)->toBeFalse()
            ->and($speaker->hidden_by_reversal_at)->not->toBeNull();
    }

    expect($result->activityHidden)->toBeTrue()
        ->and($result->activityTitle)->toBe('Propuesta a')
        ->and($result->speakersHidden)->toBe(2)
        ->and($result->speakersKept)->toBe(0)
        ->and($result->changed)->toBeTrue()
        ->and(DB::table('speakers')->count())->toBe(2)
        ->and(DB::table('event_activities')->count())->toBe(1)
        ->and(DB::table('activity_speaker')->count())->toBe(2);
});

it('CA-021-10: devuelve el aviso con los conteos para mostrarlo en pantalla', function (): void {
    [, $submission] = promotedSubmission();

    $notice = app(HideProgramOfSubmission::class)->handle(null, $submission)->notice();

    expect($notice)->toContain('Propuesta a')->toContain('2');
});

it('CA-021-10: audita la reversa con identificadores y sin datos personales', function (): void {
    [$event, $submission] = promotedSubmission();
    $actor = User::factory()->create();

    app(HideProgramOfSubmission::class)->handle($actor, $submission);

    $log = AuditLog::query()->where('event', 'submission_program_hidden')->sole();
    expect($log->causer_id)->toBe($actor->id)
        ->and($log->properties['submission_id'])->toBe($submission->id)
        ->and($log->properties['organization_id'])->toBe($event->organization_id)
        ->and($log->properties['activity_id'])->toBe(reversalActivity($event, $submission)->id)
        ->and($log->properties['speakers_hidden'])->toBe(2)
        ->and(json_encode($log->properties))->not->toContain('a1@example.com');
});

it('CA-021-10: repetir la reversa no cambia nada ni audita otra vez', function (): void {
    [$event, $submission] = promotedSubmission();
    app(HideProgramOfSubmission::class)->handle(null, $submission);
    $hiddenAt = reversalActivity($event, $submission)->hidden_by_reversal_at;
    $logs = AuditLog::query()->count();

    $second = app(HideProgramOfSubmission::class)->handle(null, $submission);

    expect($second->changed)->toBeFalse()
        ->and($second->speakersHidden)->toBe(0)
        ->and(AuditLog::query()->count())->toBe($logs)
        ->and(reversalActivity($event, $submission)->hidden_by_reversal_at?->equalTo($hiddenAt))->toBeTrue();
});

it('CA-021-10: una propuesta sin actividad ni ponentes no cambia nada', function (): void {
    $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $submission = CurrentOrganization::run($event->organization, fn (): Submission => Submission::factory()->create(['event_id' => $event->id]));

    $result = app(HideProgramOfSubmission::class)->handle(null, $submission);

    expect($result->changed)->toBeFalse()
        ->and($result->activityTitle)->toBeNull()
        ->and(AuditLog::query()->where('event', 'submission_program_hidden')->count())->toBe(0);
});

it('CA-021-11: el ponente que sigue en otra actividad publicada permanece visible', function (): void {
    [$event, $submission] = promotedSubmission();
    $shared = reversalSpeaker($event, 'a1@example.com');
    CurrentOrganization::run($event->organization, function () use ($event, $shared): void {
        $other = EventActivity::factory()->create(['event_id' => $event->id, 'is_published' => true]);
        $other->speakers()->attach($shared->id, ['organization_id' => $event->organization_id, 'event_id' => $event->id, 'position' => 0]);
    });

    $result = app(HideProgramOfSubmission::class)->handle(null, $submission);

    expect(reversalSpeaker($event, 'a1@example.com')->is_published)->toBeTrue()
        ->and(reversalSpeaker($event, 'a1@example.com')->hidden_by_reversal_at)->toBeNull()
        ->and(reversalSpeaker($event, 'a2@example.com')->is_published)->toBeFalse()
        ->and($result->speakersHidden)->toBe(1)
        ->and($result->speakersKept)->toBe(1)
        ->and(reversalActivity($event, $submission)->is_published)->toBeFalse();
});

it('CA-021-11: otra actividad no publicada o eliminada no mantiene visible al ponente', function (): void {
    [$event, $submission] = promotedSubmission();
    $speaker = reversalSpeaker($event, 'a1@example.com');
    CurrentOrganization::run($event->organization, function () use ($event, $speaker): void {
        $draft = EventActivity::factory()->create(['event_id' => $event->id, 'is_published' => false]);
        $deleted = EventActivity::factory()->create(['event_id' => $event->id, 'is_published' => true]);
        foreach ([$draft, $deleted] as $position => $activity) {
            $activity->speakers()->attach($speaker->id, ['organization_id' => $event->organization_id, 'event_id' => $event->id, 'position' => $position]);
        }
        $deleted->delete();
    });

    $result = app(HideProgramOfSubmission::class)->handle(null, $submission);

    expect(reversalSpeaker($event, 'a1@example.com')->is_published)->toBeFalse()
        ->and($result->speakersKept)->toBe(0);
});

it('CA-021-11: un ponente creado a mano y asignado a la actividad no se oculta', function (): void {
    [$event, $submission] = promotedSubmission();
    $manual = CurrentOrganization::run($event->organization, function () use ($event, $submission): Speaker {
        $speaker = Speaker::factory()->create(['event_id' => $event->id, 'email' => 'manual@example.com']);
        reversalActivity($event, $submission)->speakers()->attach($speaker->id, ['organization_id' => $event->organization_id, 'event_id' => $event->id, 'position' => 5]);

        return $speaker;
    });

    app(HideProgramOfSubmission::class)->handle(null, $submission);

    expect(reversalSpeaker($event, 'manual@example.com')->id)->toBe($manual->id)
        ->and(reversalSpeaker($event, 'manual@example.com')->is_published)->toBeTrue();
});

it('CA-021-11: el ponente reutilizado de otra propuesta no se oculta con la reversa de esta', function (): void {
    [$event, $first] = promotedSubmission(null, 'a');
    // La segunda propuesta reutiliza la ficha creada por la primera (mismo correo).
    $second = CurrentOrganization::run($event->organization, function () use ($event, $first): Submission {
        $record = Submission::factory()->create([
            'event_id' => $event->id,
            'status' => SubmissionStatus::Accepted,
            'title' => 'Propuesta b',
            // Un aviso de privacidad por evento y versión: se comparte el de la primera propuesta.
            'privacy_notice_version_id' => $first->privacy_notice_version_id,
        ]);
        SubmissionParticipant::factory()->create(['submission_id' => $record->id, 'email' => 'a1@example.com']);

        return $record;
    });
    app(PromoteSubmissionToProgram::class)->handle(null, $second);

    app(HideProgramOfSubmission::class)->handle(null, $first);

    // a1 fue creada por la primera, pero sigue en la actividad publicada de la segunda.
    expect(reversalSpeaker($event, 'a1@example.com')->is_published)->toBeTrue()
        ->and(reversalSpeaker($event, 'a2@example.com')->is_published)->toBeFalse();

    app(HideProgramOfSubmission::class)->handle(null, $second);

    // Ya ninguna publicada la retiene, pero tampoco la creó la segunda: sigue visible por no ser suya.
    expect(reversalSpeaker($event, 'a1@example.com')->is_published)->toBeTrue();
});

it('CA-021-17: revertir una propuesta no toca el programa de otra propuesta ni de otra organizacion', function (): void {
    [$event, $submission] = promotedSubmission(null, 'a');
    [$otherEvent, $otherSubmission] = promotedSubmission(null, 'z');

    app(HideProgramOfSubmission::class)->handle(null, $submission);

    expect(reversalActivity($otherEvent, $otherSubmission)->is_published)->toBeTrue()
        ->and(reversalSpeaker($otherEvent, 'z1@example.com')->is_published)->toBeTrue()
        ->and(reversalSpeaker($event, 'a1@example.com')->is_published)->toBeFalse();
});

it('CA-021-10: no oculta lo que ya estaba despublicado a mano ni le pone marca de reversa', function (): void {
    [$event, $submission] = promotedSubmission();
    CurrentOrganization::run($event->organization, fn () => Speaker::query()->where('email', 'a1@example.com')->update(['is_published' => false]));

    $result = app(HideProgramOfSubmission::class)->handle(null, $submission);

    expect(reversalSpeaker($event, 'a1@example.com')->hidden_by_reversal_at)->toBeNull()
        ->and($result->speakersHidden)->toBe(1);
});
