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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity as AuditLog;

uses(RefreshDatabase::class);

/*
 * Plan 021, F5: paso de una propuesta aceptada a ponentes y actividad (CA-021-7, 8, 9, 12). La
 * reversa (CA-021-10 y 11) está en HideProgramOfSubmissionTest. El plan 012 aún no existe: la Action
 * se invoca directo, como lo hará su Action de aceptar dentro de su transacción.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Propuesta aceptada con tantos participantes como filas se indiquen (cada una puede fijar columnas).
 *
 * @param  list<array<string, mixed>>  $participants
 * @param  array<string, mixed>  $submission
 */
function promotable(Event $event, array $participants = [[], []], array $submission = []): Submission
{
    return CurrentOrganization::run($event->organization, function () use ($event, $participants, $submission): Submission {
        $record = Submission::factory()->create([
            'event_id' => $event->id,
            'status' => SubmissionStatus::Accepted,
            'title' => 'Ciudades que respiran',
            ...$submission,
        ]);

        foreach ($participants as $index => $attributes) {
            SubmissionParticipant::factory()->create([
                'submission_id' => $record->id,
                'position' => $index + 1,
                'is_primary' => $index === 0,
                ...$attributes,
            ]);
        }

        return $record;
    });
}

function promotionEvent(): Event
{
    return Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);
}

/** @return list<Speaker> */
function speakersOf(Event $event): array
{
    return CurrentOrganization::run($event->organization, fn (): array => Speaker::query()->where('event_id', $event->id)->orderBy('created_at')->get()->all());
}

function activitiesOf(Event $event): int
{
    return CurrentOrganization::run($event->organization, fn (): int => EventActivity::query()->withTrashed()->where('event_id', $event->id)->count());
}

it('CA-021-7: crea un ponente por participante y una actividad sin fecha ni categoria enlazada a la propuesta', function (): void {
    $event = promotionEvent();
    $submission = promotable($event, [
        ['first_name' => 'Ana', 'last_name' => 'Lopez', 'email' => 'ana@example.com', 'country' => 'MX', 'company' => 'Parques', 'job_title' => 'Directora', 'bio' => 'Urbanista.'],
        ['first_name' => 'Luis', 'last_name' => 'Perez', 'email' => 'luis@example.com'],
    ]);

    $result = app(PromoteSubmissionToProgram::class)->handle(null, $submission);

    $speakers = speakersOf($event);
    expect($speakers)->toHaveCount(2)
        ->and($result->speakersCreated)->toBe(2)
        ->and($result->activityCreated)->toBeTrue();

    $ana = collect($speakers)->firstWhere('email', 'ana@example.com');
    expect($ana->first_name)->toBe('Ana')
        ->and($ana->last_name)->toBe('Lopez')
        ->and($ana->country)->toBe('MX')
        ->and($ana->company)->toBe('Parques')
        ->and($ana->job_title)->toBe('Directora')
        ->and($ana->bio)->toBe('<p>Urbanista.</p>')
        ->and($ana->is_published)->toBeTrue()
        ->and($ana->hidden_by_reversal_at)->toBeNull()
        ->and($ana->created_by_submission_id)->toBe($submission->id)
        ->and($ana->contact_id)->toBe(CurrentOrganization::run($event->organization, fn () => SubmissionParticipant::query()->where('email', 'ana@example.com')->value('contact_id')))
        ->and($ana->organization_id)->toBe($event->organization_id)
        ->and($ana->event_id)->toBe($event->id);

    $activity = CurrentOrganization::run($event->organization, fn (): EventActivity => EventActivity::query()->where('submission_id', $submission->id)->firstOrFail());
    expect($activity->title)->toBe('Ciudades que respiran')
        ->and($activity->event_id)->toBe($event->id)
        ->and($activity->is_published)->toBeTrue()
        ->and($activity->starts_on)->toBeNull()
        ->and($activity->starts_at)->toBeNull()
        ->and($activity->hidden_by_reversal_at)->toBeNull()
        ->and(DB::table('activity_activity_category')->where('activity_id', $activity->id)->count())->toBe(0)
        ->and(DB::table('activity_speaker')->where('activity_id', $activity->id)->orderBy('position')->pluck('speaker_id')->all())
        ->toBe([$ana->id, collect($speakers)->firstWhere('email', 'luis@example.com')->id]);
});

it('CA-021-7: el participante anterior al plan 021 (solo full_name) parte en nombre y apellido', function (): void {
    $event = promotionEvent();
    $submission = promotable($event, [
        ['full_name' => 'María Fernanda de la Torre', 'first_name' => null, 'last_name' => null, 'email' => 'maria@example.com'],
        ['full_name' => 'Cher', 'first_name' => null, 'last_name' => null, 'email' => 'cher@example.com'],
    ]);

    app(PromoteSubmissionToProgram::class)->handle(null, $submission);

    $maria = collect(speakersOf($event))->firstWhere('email', 'maria@example.com');
    $cher = collect(speakersOf($event))->firstWhere('email', 'cher@example.com');
    expect($maria->first_name)->toBe('María')
        ->and($maria->last_name)->toBe('Fernanda de la Torre')
        // Un nombre de una sola palabra deja un apellido provisional que el organizador corrige (la base exige ambos).
        ->and($cher->first_name)->toBe('Cher')
        ->and($cher->last_name)->toBe('-');
});

it('CA-021-7: el texto de la biografia llega saneado como HTML', function (): void {
    $event = promotionEvent();
    $submission = promotable($event, [['bio' => "Hola <script>alert(1)</script>\n\nSegundo parrafo", 'email' => 'bio@example.com']]);

    app(PromoteSubmissionToProgram::class)->handle(null, $submission);

    $bio = speakersOf($event)[0]->bio;
    expect($bio)->not->toContain('<script')
        ->and($bio)->toContain('Segundo parrafo');
});

it('CA-021-7: deja auditoria con identificadores y sin datos personales', function (): void {
    $event = promotionEvent();
    $actor = User::factory()->create();
    $submission = promotable($event, [['email' => 'audit@example.com', 'first_name' => 'Zoe']]);

    app(PromoteSubmissionToProgram::class)->handle($actor, $submission);

    $log = AuditLog::query()->where('event', 'submission_promoted')->sole();
    expect($log->causer_id)->toBe($actor->id)
        ->and($log->properties['submission_id'])->toBe($submission->id)
        ->and($log->properties['organization_id'])->toBe($event->organization_id)
        ->and($log->properties['speakers_created'])->toBe(1)
        ->and(json_encode($log->properties))->not->toContain('audit@example.com')
        ->and(json_encode($log->properties))->not->toContain('Zoe');
});

it('CA-021-8: ejecutar el paso otra vez no crea ponentes ni actividades adicionales', function (): void {
    $event = promotionEvent();
    $submission = promotable($event);

    app(PromoteSubmissionToProgram::class)->handle(null, $submission);
    $before = [count(speakersOf($event)), activitiesOf($event), DB::table('activity_speaker')->count(), AuditLog::query()->count()];

    $second = app(PromoteSubmissionToProgram::class)->handle(null, $submission);

    expect([count(speakersOf($event)), activitiesOf($event), DB::table('activity_speaker')->count(), AuditLog::query()->count()])->toBe($before)
        ->and($second->speakersCreated)->toBe(0)
        ->and($second->activityCreated)->toBeFalse()
        ->and($second->changed)->toBeFalse()
        ->and($second->speakers)->toHaveCount(2)
        ->and($second->activity)->not->toBeNull();
});

it('CA-021-8: no vuelve a crear al ponente aunque el organizador haya cambiado su correo', function (): void {
    $event = promotionEvent();
    $submission = promotable($event, [['email' => 'viejo@example.com']]);

    app(PromoteSubmissionToProgram::class)->handle(null, $submission);
    CurrentOrganization::run($event->organization, fn () => Speaker::query()->update(['email' => 'nuevo@example.com']));

    app(PromoteSubmissionToProgram::class)->handle(null, $submission);

    expect(speakersOf($event))->toHaveCount(1)
        ->and(speakersOf($event)[0]->email)->toBe('nuevo@example.com');
});

it('CA-021-8: la propuesta sin participantes crea solo la actividad', function (): void {
    $event = promotionEvent();

    $result = app(PromoteSubmissionToProgram::class)->handle(null, promotable($event, []));

    expect(speakersOf($event))->toBe([])
        ->and($result->activityCreated)->toBeTrue()
        ->and(activitiesOf($event))->toBe(1);
});

it('CA-021-9: reutiliza al ponente del evento por correo sin distinguir mayusculas y rellena solo lo vacio', function (): void {
    $event = promotionEvent();
    $existing = CurrentOrganization::run($event->organization, fn (): Speaker => Speaker::factory()->create([
        'event_id' => $event->id,
        'first_name' => 'Anita',
        'last_name' => 'Editada',
        'email' => 'ANA@Example.com',
        'company' => 'Editada SA',
        'job_title' => null,
        'country' => null,
        'bio' => '<p>Mi bio</p>',
    ]));
    $submission = promotable($event, [[
        'first_name' => 'Ana', 'last_name' => 'Lopez', 'email' => 'ana@example.com',
        'company' => 'Otra empresa', 'job_title' => 'Directora', 'country' => 'CO', 'bio' => 'Bio de la propuesta',
    ]]);

    $result = app(PromoteSubmissionToProgram::class)->handle(null, $submission);

    expect(speakersOf($event))->toHaveCount(1)
        ->and($result->speakersCreated)->toBe(0)
        ->and($result->speakersReused)->toBe(1);

    $speaker = CurrentOrganization::run($event->organization, fn (): Speaker => Speaker::query()->findOrFail($existing->id));
    expect($speaker->first_name)->toBe('Anita')
        ->and($speaker->last_name)->toBe('Editada')
        ->and($speaker->email)->toBe('ANA@Example.com')
        ->and($speaker->company)->toBe('Editada SA')
        ->and($speaker->bio)->toBe('<p>Mi bio</p>')
        ->and($speaker->job_title)->toBe('Directora')
        ->and($speaker->country)->toBe('CO')
        ->and($speaker->contact_id)->not->toBeNull()
        // Fue creada por otra vía: la propuesta no se la apropia.
        ->and($speaker->created_by_submission_id)->toBeNull();

    $activity = CurrentOrganization::run($event->organization, fn (): EventActivity => EventActivity::query()->where('submission_id', $submission->id)->firstOrFail());
    expect(DB::table('activity_speaker')->where('activity_id', $activity->id)->pluck('speaker_id')->all())->toBe([$existing->id]);
});

it('CA-021-9: nunca reutiliza un ponente de otro evento ni de otra organizacion', function (): void {
    $event = promotionEvent();
    $otherEvent = Event::factory()->create(['organization_id' => $event->organization_id]);
    $foreignEvent = promotionEvent();
    $sameEmailElsewhere = CurrentOrganization::run($event->organization, fn (): Speaker => Speaker::factory()->create(['event_id' => $otherEvent->id, 'email' => 'ana@example.com', 'company' => 'Intacta']));
    $foreign = CurrentOrganization::run($foreignEvent->organization, fn (): Speaker => Speaker::factory()->create(['event_id' => $foreignEvent->id, 'email' => 'ana@example.com', 'company' => 'Ajena']));
    $submission = promotable($event, [['email' => 'ana@example.com', 'company' => 'Parques']]);

    app(PromoteSubmissionToProgram::class)->handle(null, $submission);

    expect(speakersOf($event))->toHaveCount(1)
        ->and(speakersOf($event)[0]->company)->toBe('Parques')
        ->and(DB::table('speakers')->where('id', $sameEmailElsewhere->id)->value('company'))->toBe('Intacta')
        ->and(DB::table('speakers')->where('id', $foreign->id)->value('company'))->toBe('Ajena')
        ->and(DB::table('activity_speaker')->whereIn('speaker_id', [$sameEmailElsewhere->id, $foreign->id])->count())->toBe(0);
});

it('CA-021-9: un ponente eliminado no se reutiliza y libera su correo', function (): void {
    $event = promotionEvent();
    $deleted = CurrentOrganization::run($event->organization, function () use ($event): Speaker {
        $speaker = Speaker::factory()->create(['event_id' => $event->id, 'email' => 'ana@example.com']);
        $speaker->delete();

        return $speaker;
    });

    app(PromoteSubmissionToProgram::class)->handle(null, promotable($event, [['email' => 'ana@example.com']]));

    expect(speakersOf($event))->toHaveCount(1)
        ->and(speakersOf($event)[0]->id)->not->toBe($deleted->id);
});

it('CA-021-9: dos participantes con el mismo correo comparten una sola ficha', function (): void {
    $event = promotionEvent();

    app(PromoteSubmissionToProgram::class)->handle(null, promotable($event, [['email' => 'dup@example.com'], ['email' => 'DUP@example.com']]));

    expect(speakersOf($event))->toHaveCount(1);
});

it('CA-021-12: al aceptar otra vez reutiliza y republica lo oculto por reversa sin pisar lo editado', function (): void {
    $event = promotionEvent();
    $submission = promotable($event, [['email' => 'ana@example.com', 'company' => 'Parques'], ['email' => 'luis@example.com']]);
    app(PromoteSubmissionToProgram::class)->handle(null, $submission);
    $idsBefore = collect(speakersOf($event))->pluck('id')->sort()->values()->all();
    $activityBefore = CurrentOrganization::run($event->organization, fn (): string => EventActivity::query()->where('submission_id', $submission->id)->value('id'));

    app(HideProgramOfSubmission::class)->handle(null, $submission);
    CurrentOrganization::run($event->organization, fn () => Speaker::query()->where('email', 'ana@example.com')->update(['company' => 'Editada a mano']));

    $result = app(PromoteSubmissionToProgram::class)->handle(null, $submission);

    expect(collect(speakersOf($event))->pluck('id')->sort()->values()->all())->toBe($idsBefore)
        ->and(activitiesOf($event))->toBe(1)
        ->and($result->speakersCreated)->toBe(0)
        ->and($result->activityCreated)->toBeFalse()
        ->and($result->changed)->toBeTrue();

    $activity = CurrentOrganization::run($event->organization, fn (): EventActivity => EventActivity::query()->findOrFail($activityBefore));
    expect($activity->is_published)->toBeTrue()
        ->and($activity->hidden_by_reversal_at)->toBeNull();

    foreach (speakersOf($event) as $speaker) {
        expect($speaker->is_published)->toBeTrue()
            ->and($speaker->hidden_by_reversal_at)->toBeNull();
    }

    expect(collect(speakersOf($event))->firstWhere('email', 'ana@example.com')->company)->toBe('Editada a mano')
        ->and(DB::table('activity_speaker')->where('activity_id', $activityBefore)->count())->toBe(2);
});

it('CA-021-12: lo despublicado a mano (sin marca de reversa) no se republica al aceptar otra vez', function (): void {
    $event = promotionEvent();
    $submission = promotable($event, [['email' => 'ana@example.com']]);
    app(PromoteSubmissionToProgram::class)->handle(null, $submission);
    CurrentOrganization::run($event->organization, function () use ($submission): void {
        Speaker::query()->update(['is_published' => false]);
        EventActivity::query()->where('submission_id', $submission->id)->update(['is_published' => false]);
    });

    $result = app(PromoteSubmissionToProgram::class)->handle(null, $submission);

    expect($result->changed)->toBeFalse()
        ->and(speakersOf($event)[0]->is_published)->toBeFalse()
        ->and(CurrentOrganization::run($event->organization, fn (): bool => EventActivity::query()->where('submission_id', $submission->id)->value('is_published')))->toBeFalse();
});

it('CA-021-12: una actividad eliminada por el organizador no se recrea ni se resucita', function (): void {
    $event = promotionEvent();
    $submission = promotable($event, [['email' => 'ana@example.com']]);
    app(PromoteSubmissionToProgram::class)->handle(null, $submission);
    CurrentOrganization::run($event->organization, fn () => EventActivity::query()->where('submission_id', $submission->id)->firstOrFail()->delete());

    $result = app(PromoteSubmissionToProgram::class)->handle(null, $submission);

    expect($result->activity)->toBeNull()
        ->and($result->activityCreated)->toBeFalse()
        ->and(activitiesOf($event))->toBe(1)
        ->and(CurrentOrganization::run($event->organization, fn (): int => EventActivity::query()->count()))->toBe(0);
});

it('CA-021-7: la base exige que la propuesta y la actividad sean del mismo evento', function (): void {
    $event = promotionEvent();
    $otherEvent = Event::factory()->create(['organization_id' => $event->organization_id]);
    $submissionOfOtherEvent = promotable($otherEvent, []);

    expect(fn () => CurrentOrganization::run($event->organization, fn () => EventActivity::factory()->create([
        'event_id' => $event->id,
        'submission_id' => $submissionOfOtherEvent->id,
    ])))->toThrow(QueryException::class);

    expect(fn () => CurrentOrganization::run($event->organization, fn () => Speaker::factory()->create([
        'event_id' => $event->id,
        'created_by_submission_id' => $submissionOfOtherEvent->id,
    ])))->toThrow(QueryException::class);
});

it('CA-021-7: la base acepta la propuesta del mismo evento y una actividad sin propuesta', function (): void {
    $event = promotionEvent();
    $submission = promotable($event, []);

    $withSubmission = CurrentOrganization::run($event->organization, fn (): EventActivity => EventActivity::factory()->create(['event_id' => $event->id, 'submission_id' => $submission->id]));
    $manual = CurrentOrganization::run($event->organization, fn (): EventActivity => EventActivity::factory()->create(['event_id' => $event->id]));

    expect($withSubmission->submission_id)->toBe($submission->id)
        ->and($manual->submission_id)->toBeNull();
});

it('CA-021-17: una propuesta de otra organizacion no se promueve dentro de la organizacion ajena', function (): void {
    $event = promotionEvent();
    $foreign = promotionEvent();
    $submission = promotable($foreign, [['email' => 'x@example.com']]);

    app(PromoteSubmissionToProgram::class)->handle(null, $submission);

    expect(speakersOf($event))->toBe([])
        ->and(activitiesOf($event))->toBe(0)
        ->and(speakersOf($foreign))->toHaveCount(1)
        ->and(activitiesOf($foreign))->toBe(1);
});
