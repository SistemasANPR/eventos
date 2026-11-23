<?php

declare(strict_types=1);

use App\Actions\Submissions\IssueFormSession;
use App\Actions\Submissions\SubmitSubmission;
use App\Enums\CallStatus;
use App\Enums\FormDefinitionStatus;
use App\Enums\SubmissionFileStatus;
use App\Enums\SubmissionOrigin;
use App\Enums\SubmissionStatus;
use App\Exceptions\Domain\CallNotOpen;
use App\Exceptions\Domain\CallNotReady;
use App\Exceptions\Domain\FormOutdated;
use App\Exceptions\Domain\IdempotencyPayloadMismatch;
use App\Models\CallForSubmissions;
use App\Models\Contact;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\SubmissionParticipant;
use App\Models\Track;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeSubmissionForm;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    FakeSubmissionForm::install();
    // Dentro de la ventana de la convocatoria del factory (2027-01-10 15:00 a 2027-02-15 23:00 UTC).
    $this->travelTo('2027-01-20 12:00:00');
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Convocatoria abierta con formulario publicado (un campo obligatorio y uno opcional), eje y aviso vigente.
 * Deja la organización en contexto.
 *
 * @param  array<string, mixed>  $notice
 * @return array{org: Organization, event: Event, call: CallForSubmissions, form: FormDefinition, track: Track, notice: PrivacyNotice}
 */
function subScenario(array $notice = [], bool $publishedNotice = true): array
{
    $org = Organization::factory()->create();
    CurrentOrganization::set($org);
    $event = Event::factory()->create(['organization_id' => $org->id]);
    $form = FormDefinition::factory()->create([
        'organization_id' => $org->id,
        'event_id' => $event->id,
        'status' => FormDefinitionStatus::Published,
        'published_at' => now(),
        'version' => 3,
        'fields' => [
            ['key' => 'summary', 'type' => 'long_text', 'required' => true, 'label' => ['es' => 'Resumen'], 'config' => ['maxLength' => 500], 'retiredAt' => null],
            ['key' => 'level', 'type' => 'short_text', 'required' => false, 'label' => ['es' => 'Nivel'], 'config' => ['maxLength' => 50], 'retiredAt' => null],
        ],
    ]);
    $call = CallForSubmissions::factory()->published()->create([
        'organization_id' => $org->id,
        'event_id' => $event->id,
        'form_definition_id' => $form->id,
        'locales' => ['es', 'en'],
    ]);
    $call->forceFill(['min_participants' => 1, 'max_participants' => 3])->save();
    $track = Track::factory()->create(['organization_id' => $org->id, 'event_id' => $event->id]);
    $factory = PrivacyNotice::factory();
    $notice = ($publishedNotice ? $factory->published() : $factory)->create(['organization_id' => $org->id, 'event_id' => $event->id, ...$notice]);

    return compact('org', 'event', 'call', 'form', 'track', 'notice');
}

/**
 * Entrada válida con una clave de idempotencia recién emitida.
 *
 * @param  array<string, mixed>  $ctx
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function subInput(array $ctx, array $overrides = []): array
{
    $key = app(IssueFormSession::class)->handle($ctx['call'])['idempotency_key'];

    return array_replace([
        'idempotency_key' => $key,
        'form_version' => 3,
        'locale' => 'es',
        'title' => 'Mi propuesta',
        'track_id' => $ctx['track']->id,
        'answers' => ['summary' => 'Un resumen', 'level' => 'Básico'],
        'participants' => [
            ['first_name' => 'Ana', 'last_name' => 'Pérez', 'email' => 'Ana@Example.com ', 'country' => 'MX', 'mobile' => '52155123456789', 'state' => 'CDMX', 'city' => 'Ciudad de México', 'phone' => '+52 55 1234 5678', 'job_title' => 'Dra.', 'company' => 'ACME', 'bio' => 'Bio', 'is_primary' => true],
        ],
        'consent' => true,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $ctx
 * @param  array<string, mixed>  $input
 */
function subSubmit(array $ctx, array $input): Submission
{
    return app(SubmitSubmission::class)->handle($ctx['call'], $input)->submission;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function subParticipant(string $email, array $overrides = []): array
{
    return array_replace(['first_name' => 'Persona', 'last_name' => $email, 'email' => $email, 'country' => 'CO', 'mobile' => '52155123456789', 'state' => 'CDMX', 'city' => 'Ciudad de México', 'phone' => '+52 55 1234 5678', 'job_title' => 'Dra.', 'company' => 'ACME', 'bio' => 'Semblanza profesional', 'is_primary' => false], $overrides);
}

/**
 * @param  array<string, mixed>  $ctx
 * @param  array<string, mixed>  $input
 * @return array<string, list<string>> errores de validación del envío (vacío si se aceptó)
 */
function subErrors(array $ctx, array $input): array
{
    try {
        app(SubmitSubmission::class)->handle($ctx['call'], $input);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('CA-006-1: creates a submitted proposal with participants, answers and a contact', function (): void {
    $ctx = subScenario();

    $outcome = app(SubmitSubmission::class)->handle($ctx['call'], subInput($ctx));
    $submission = $outcome->submission->fresh();

    expect($outcome->created)->toBeTrue()
        ->and($submission->status)->toBe(SubmissionStatus::Submitted)
        ->and($submission->origin)->toBe(SubmissionOrigin::Call)
        ->and($submission->organization_id)->toBe($ctx['org']->id)
        ->and($submission->event_id)->toBe($ctx['event']->id)
        ->and($submission->call_for_submissions_id)->toBe($ctx['call']->id)
        ->and($submission->submission_type_id)->toBe($ctx['call']->submission_type_id)
        ->and($submission->track_id)->toBe($ctx['track']->id)
        ->and($submission->answers)->toEqual(['summary' => 'Un resumen', 'level' => 'Básico']);

    $participant = $submission->participants()->sole();
    expect($participant->is_primary)->toBeTrue()
        ->and($participant->email)->toBe('ana@example.com')
        ->and($participant->contact->email)->toBe('ana@example.com')
        ->and(Contact::query()->count())->toBe(1);
});

it('CA-006-2: rejects scheduled, paused and closed calls with CALL_NOT_OPEN and stores nothing', function (CallStatus $status, ?string $opensAt): void {
    $ctx = subScenario();
    $changes = ['status' => $status];
    if ($opensAt !== null) {
        $changes['opens_at'] = $opensAt;
        $changes['closes_at'] = '2027-04-01 00:00:00';
    }
    $ctx['call']->forceFill($changes)->save();

    expect(fn () => subSubmit($ctx, subInput($ctx)))->toThrow(CallNotOpen::class)
        ->and(Submission::query()->count())->toBe(0)
        ->and(Contact::query()->count())->toBe(0);
})->with([
    'scheduled' => [CallStatus::Published, '2027-03-01 00:00:00'],
    'paused' => [CallStatus::Paused, null],
    'emergency closed' => [CallStatus::Closed, null],
]);

it('CA-006-2: a draft call is not found', function (): void {
    $ctx = subScenario();
    $ctx['call']->forceFill(['status' => CallStatus::Draft])->save();

    expect(fn () => subSubmit($ctx, subInput($ctx)))->toThrow(ModelNotFoundException::class);
});

it('CA-006-3: accepts one second before closing and rejects at the exact close', function (): void {
    $ctx = subScenario();
    $input = subInput($ctx);

    $this->travelTo($ctx['call']->closes_at->copy()->subSecond());
    $accepted = subSubmit($ctx, $input);
    expect($accepted->exists)->toBeTrue();

    $this->travelTo($ctx['call']->closes_at->copy());
    expect(fn () => subSubmit($ctx, subInput($ctx, ['title' => 'Otra'])))->toThrow(CallNotOpen::class)
        ->and(Submission::query()->count())->toBe(1);
});

it('CA-006-4: rejects invalid answers per field and stores nothing', function (): void {
    $ctx = subScenario();

    $errors = subErrors($ctx, subInput($ctx, ['answers' => ['level' => str_repeat('x', 51), 'ghost' => 'x']]));

    expect($errors)->toHaveKeys(['answers.summary', 'answers.level', 'answers.ghost'])
        ->and(Submission::query()->count())->toBe(0);
});

it('CA-006-5: enforces participant count, required data, one primary and distinct emails', function (array $participants, string $errorKey): void {
    $ctx = subScenario();

    $errors = subErrors($ctx, subInput($ctx, ['participants' => $participants, 'third_parties_authorized' => true]));

    expect(array_keys($errors))->toContain($errorKey)
        ->and(Submission::query()->count())->toBe(0);
})->with([
    'none' => [[], 'participants'],
    'four' => [[subParticipant('a@x.com', ['is_primary' => true]), subParticipant('b@x.com'), subParticipant('c@x.com'), subParticipant('d@x.com')], 'participants'],
    'no name' => [[['first_name' => '', 'last_name' => 'Pérez', 'email' => 'a@x.com', 'country' => 'MX', 'mobile' => '52155123456789', 'state' => 'CDMX', 'city' => 'Ciudad de México', 'phone' => '+52 55 1234 5678', 'job_title' => 'Dra.', 'company' => 'ACME', 'bio' => 'Semblanza profesional', 'is_primary' => true]], 'participants.0.first_name'],
    'bad email' => [[subParticipant('no-es-correo', ['is_primary' => true])], 'participants.0.email'],
    'bad country' => [[subParticipant('a@x.com', ['is_primary' => true, 'country' => 'ZZ'])], 'participants.0.country'],
    'no primary' => [[subParticipant('a@x.com')], 'participants'],
    'two primaries' => [[subParticipant('a@x.com', ['is_primary' => true]), subParticipant('b@x.com', ['is_primary' => true])], 'participants'],
    'duplicate email' => [[subParticipant('a@x.com', ['is_primary' => true]), subParticipant('A@X.com')], 'participants.1.email'],
]);

it('CA-006-5: accepts the minimum and the maximum number of participants', function (): void {
    $ctx = subScenario();
    $three = [subParticipant('a@x.com', ['is_primary' => true]), subParticipant('b@x.com'), subParticipant('c@x.com')];

    $submission = subSubmit($ctx, subInput($ctx, ['participants' => $three, 'third_parties_authorized' => true]));

    expect($submission->participants()->count())->toBe(3);
});

it('CA-006-6: rejects values over the length limits per field', function (string $path, array $input, string $errorKey): void {
    $ctx = subScenario();
    $base = subInput($ctx);
    $input = array_replace_recursive($base, $input);

    expect(array_keys(subErrors($ctx, $input)))->toContain($errorKey);
})->with([
    'title' => ['title', ['title' => str_repeat('t', 201)], 'title'],
    'first name' => ['name', ['participants' => [['first_name' => str_repeat('n', 101)]]], 'participants.0.first_name'],
    'last name' => ['surname', ['participants' => [['last_name' => str_repeat('n', 101)]]], 'participants.0.last_name'],
    'job title' => ['job', ['participants' => [['job_title' => str_repeat('j', 121)]]], 'participants.0.job_title'],
    'company' => ['company', ['participants' => [['company' => str_repeat('c', 161)]]], 'participants.0.company'],
    'bio' => ['bio', ['participants' => [['bio' => str_repeat('b', 2001)]]], 'participants.0.bio'],
    'email' => ['email', ['participants' => [['email' => str_repeat('e', 250).'@x.com']]], 'participants.0.email'],
]);

it('CA-006-6: stores HTML tags as plain text', function (): void {
    $ctx = subScenario();

    $submission = subSubmit($ctx, subInput($ctx, ['title' => '<script>alert(1)</script>Hola']));

    expect($submission->fresh()->title)->toBe('<script>alert(1)</script>Hola');
});

it('CA-006-7: ignores organization, event, status, dates and call sent by the client', function (): void {
    $ctx = subScenario();
    $otherOrg = Organization::factory()->create();

    $input = subInput($ctx, [
        'organization_id' => $otherOrg->id,
        'event_id' => (string) Str::uuid(),
        'status' => 'accepted',
        'submitted_at' => '2000-01-01 00:00:00',
        'call_for_submissions_id' => (string) Str::uuid(),
    ]);
    $submission = subSubmit($ctx, $input)->fresh();

    expect($submission->organization_id)->toBe($ctx['org']->id)
        ->and($submission->event_id)->toBe($ctx['event']->id)
        ->and($submission->call_for_submissions_id)->toBe($ctx['call']->id)
        ->and($submission->status)->toBe(SubmissionStatus::Submitted)
        ->and($submission->submitted_at->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('CA-006-8: rejects tracks from another event, another organization or archived', function (string $kind): void {
    $ctx = subScenario();
    $track = match ($kind) {
        'other event' => Track::factory()->create(['organization_id' => $ctx['org']->id, 'event_id' => Event::factory()->create(['organization_id' => $ctx['org']->id])->id]),
        'other org' => (function (): Track {
            $other = Organization::factory()->create();
            $created = CurrentOrganization::run($other, fn (): Track => Track::factory()->create(['organization_id' => $other->id]));

            return $created;
        })(),
        'archived' => tap(Track::factory()->create(['organization_id' => $ctx['org']->id, 'event_id' => $ctx['event']->id]), fn (Track $t) => $t->forceFill(['archived_at' => now()])->save()),
        default => throw new InvalidArgumentException("Unknown track case: {$kind}"),
    };

    $errors = subErrors($ctx, subInput($ctx, ['track_id' => $track->id]));

    expect(array_keys($errors))->toBe(['track_id'])
        ->and(Submission::query()->count())->toBe(0);
})->with(['other event', 'other org', 'archived']);

it('CA-006-9: rolls everything back when creating a participant fails', function (): void {
    $ctx = subScenario();
    $count = 0;
    EventBus::listen('eloquent.created: '.SubmissionParticipant::class, function () use (&$count): void {
        if (++$count === 2) {
            throw new RuntimeException('fallo simulado');
        }
    });
    $input = subInput($ctx, [
        'participants' => [subParticipant('a@x.com', ['is_primary' => true]), subParticipant('b@x.com')],
        'third_parties_authorized' => true,
    ]);

    try {
        expect(fn () => subSubmit($ctx, $input))->toThrow(RuntimeException::class);
    } finally {
        EventBus::forget('eloquent.created: '.SubmissionParticipant::class);
    }

    expect(Submission::query()->count())->toBe(0)
        ->and(SubmissionParticipant::query()->count())->toBe(0)
        ->and(Contact::query()->count())->toBe(0);
});

it('CA-006-10: reuses an existing contact without modifying it, and creates a new one for another organization', function (): void {
    $ctx = subScenario();
    $existing = Contact::factory()->create([
        'organization_id' => $ctx['org']->id,
        'full_name' => 'Nombre Original',
        'email' => 'Ana@Example.com',
        'country' => 'AR',
    ]);
    $otherOrg = Organization::factory()->create();
    $foreign = CurrentOrganization::run($otherOrg, fn (): Contact => Contact::factory()->create(['organization_id' => $otherOrg->id, 'email' => 'nueva@example.com']));

    $submission = subSubmit($ctx, subInput($ctx, [
        'participants' => [
            ['first_name' => 'Ana', 'last_name' => 'Distinta', 'email' => 'ana@example.com', 'country' => 'MX', 'mobile' => '52155123456789', 'state' => 'CDMX', 'city' => 'Ciudad de México', 'phone' => '+52 55 1234 5678', 'job_title' => 'Dra.', 'company' => 'ACME', 'bio' => 'Semblanza profesional', 'is_primary' => true],
            subParticipant('nueva@example.com'),
        ],
        'third_parties_authorized' => true,
    ]));

    $linked = $submission->participants()->get();
    expect($linked[0]->contact_id)->toBe($existing->id)
        ->and($existing->fresh()->only(['full_name', 'country']))->toBe(['full_name' => 'Nombre Original', 'country' => 'AR'])
        ->and($linked[1]->contact_id)->not->toBe($foreign->id)
        ->and(Contact::query()->where('email', 'nueva@example.com')->count())->toBe(1);
});

it('CA-006-11: rejects with CALL_NOT_READY when the call has no published form', function (string $case): void {
    $ctx = subScenario();
    if ($case === 'no form') {
        $ctx['call']->forceFill(['form_definition_id' => null])->save();
    } else {
        $ctx['form']->forceFill(['status' => FormDefinitionStatus::Draft])->save();
    }

    expect(fn () => subSubmit($ctx, subInput($ctx)))->toThrow(CallNotReady::class)
        ->and(Submission::query()->count())->toBe(0);
})->with(['no form', 'draft form']);

it('CA-006-12: rejects an outdated form version with FORM_OUTDATED and stores nothing', function (): void {
    $ctx = subScenario();

    expect(fn () => subSubmit($ctx, subInput($ctx, ['form_version' => 2])))->toThrow(FormOutdated::class)
        ->and(Submission::query()->count())->toBe(0);
});

it('CA-006-12: loads the form under a row lock inside the transaction', function (): void {
    $ctx = subScenario();
    $levels = [];
    DB::listen(function ($query) use (&$levels): void {
        if (str_contains($query->sql, 'form_definitions') && str_contains($query->sql, 'for update')) {
            $levels[] = DB::transactionLevel();
        }
    });

    subSubmit($ctx, subInput($ctx));

    // El nivel base de la prueba es 1 (RefreshDatabase); la transacción de la Action suma otro.
    expect($levels)->not->toBeEmpty()->and(min($levels))->toBeGreaterThanOrEqual(2);
});

it('CA-006-13: keeps form version and a snapshot that later label edits do not change', function (): void {
    $ctx = subScenario();
    $submission = subSubmit($ctx, subInput($ctx));

    $fields = $ctx['form']->fields;
    $fields[0]['label'] = ['es' => 'Etiqueta nueva'];
    $ctx['form']->forceFill(['fields' => $fields, 'version' => 4])->save();

    $stored = $submission->fresh();
    expect($stored->form_version)->toBe(3)
        ->and($stored->form_snapshot['version'])->toBe(3)
        ->and($stored->form_snapshot['fields'][0]['label'])->toBe(['es' => 'Resumen'])
        ->and(strlen((string) json_encode($stored->form_snapshot)))->toBeLessThanOrEqual(65536);
});

it('CA-006-14: the same key and payload returns the same proposal once', function (): void {
    $ctx = subScenario();
    $input = subInput($ctx);

    $first = app(SubmitSubmission::class)->handle($ctx['call'], $input);
    $second = app(SubmitSubmission::class)->handle($ctx['call'], $input);

    expect($first->created)->toBeTrue()
        ->and($second->created)->toBeFalse()
        ->and($second->submission->id)->toBe($first->submission->id)
        ->and(Submission::query()->count())->toBe(1)
        ->and(SubmissionParticipant::query()->count())->toBe(1);
});

it('CA-006-15: the same key with another payload is a conflict and changes nothing', function (): void {
    $ctx = subScenario();
    $input = subInput($ctx);
    $first = subSubmit($ctx, $input);

    expect(fn () => subSubmit($ctx, array_replace($input, ['title' => 'Otro título'])))->toThrow(IdempotencyPayloadMismatch::class)
        ->and($first->fresh()->title)->toBe('Mi propuesta')
        ->and(Submission::query()->count())->toBe(1);
});

it('CA-006-15: a key the server never issued is rejected with a validation error', function (): void {
    $ctx = subScenario();

    $errors = subErrors($ctx, subInput($ctx, ['idempotency_key' => (string) Str::uuid()]));

    expect(array_keys($errors))->toBe(['idempotency_key'])
        ->and(Submission::query()->count())->toBe(0);
});

it('CA-006-15: a key issued for another call is rejected', function (): void {
    $ctx = subScenario();
    $other = CallForSubmissions::factory()->published()->create(['organization_id' => $ctx['org']->id, 'event_id' => $ctx['event']->id]);
    $foreignKey = app(IssueFormSession::class)->handle($other)['idempotency_key'];

    expect(array_keys(subErrors($ctx, subInput($ctx, ['idempotency_key' => $foreignKey]))))->toBe(['idempotency_key']);
});

/**
 * Simula la carrera de CA-006-16: justo después de que la Action no encuentra la clave, otra
 * petición «gana» y guarda su propuesta. Una prueba con dos conexiones reales no es viable
 * aquí: `RefreshDatabase` envuelve cada prueba en una transacción sin confirmar que una segunda
 * conexión no vería, y PHP corre en un solo proceso. El choque se produce de verdad en la base
 * (violación de `unique(organization_id, idempotency_key)`), que es lo que la Action resuelve.
 *
 * @param  array<string, mixed>  $ctx
 */
function subRaceWinner(array $ctx, string $key, string $hash): void
{
    $armed = true;
    DB::listen(function ($query) use (&$armed, $ctx, $key, $hash): void {
        if (! $armed || ! str_contains($query->sql, 'from "submissions"') || ! str_contains($query->sql, 'idempotency_key')) {
            return;
        }
        $armed = false;

        Submission::factory()->create([
            'organization_id' => $ctx['org']->id,
            'event_id' => $ctx['event']->id,
            'call_for_submissions_id' => $ctx['call']->id,
            'submission_type_id' => $ctx['call']->submission_type_id,
            'form_definition_id' => $ctx['form']->id,
            'track_id' => $ctx['track']->id,
            'privacy_notice_version_id' => $ctx['notice']->id,
            'idempotency_key' => $key,
            'payload_hash' => $hash,
        ]);
    });
}

it('CA-006-16: a lost uniqueness race resolves to the winner proposal without an error', function (): void {
    $ctx = subScenario();
    $reference = subSubmit($ctx, subInput($ctx));
    $input = subInput($ctx);
    subRaceWinner($ctx, $input['idempotency_key'], (string) $reference->payload_hash);

    $outcome = app(SubmitSubmission::class)->handle($ctx['call'], $input);

    expect($outcome->created)->toBeFalse()
        ->and(Submission::query()->where('idempotency_key', $input['idempotency_key'])->count())->toBe(1)
        ->and($outcome->submission->idempotency_key)->toBe($input['idempotency_key']);
});

it('CA-006-16: a lost race against a different payload is a conflict, not a 500', function (): void {
    $ctx = subScenario();
    $input = subInput($ctx);
    subRaceWinner($ctx, $input['idempotency_key'], hash('sha256', 'otra carga'));

    expect(fn () => app(SubmitSubmission::class)->handle($ctx['call'], $input))->toThrow(IdempotencyPayloadMismatch::class)
        ->and(Submission::query()->count())->toBe(1);
});

it('CA-006-50: rejects a submission without accepting the privacy notice', function (): void {
    $ctx = subScenario();

    $errors = subErrors($ctx, subInput($ctx, ['consent' => false]));

    expect(array_keys($errors))->toBe(['consent'])
        ->and(Submission::query()->count())->toBe(0);
});

it('CA-006-51: records the notice version, the consent date in UTC and the locale', function (): void {
    $ctx = subScenario();

    $submission = subSubmit($ctx, subInput($ctx, ['locale' => 'en', 'privacy_notice_version_id' => $ctx['notice']->id]))->fresh();

    expect($submission->privacy_notice_version_id)->toBe($ctx['notice']->id)
        ->and($submission->consented_at->utc()->toDateTimeString())->toBe('2027-01-20 12:00:00')
        ->and($submission->locale)->toBe('en')
        ->and(array_keys($submission->getAttributes()))->not->toContain('ip');
});

it('CA-006-52: rejects a notice version that is no longer the current one', function (): void {
    $ctx = subScenario();
    $newer = PrivacyNotice::factory()->published()->create(['organization_id' => $ctx['org']->id, 'event_id' => $ctx['event']->id, 'version' => 2]);

    $stale = subErrors($ctx, subInput($ctx, ['privacy_notice_version_id' => $ctx['notice']->id]));
    $current = subErrors($ctx, subInput($ctx, ['privacy_notice_version_id' => $newer->id]));

    expect(array_keys($stale))->toBe(['privacy_notice_version_id'])
        ->and($current)->toBe([])
        ->and(Submission::query()->sole()->privacy_notice_version_id)->toBe($newer->id);
});

it('CA-006-53: rejects with CALL_NOT_READY without a published notice in the submission language', function (string $case): void {
    $ctx = $case === 'unpublished'
        ? subScenario([], false)
        : subScenario(['body' => ['es' => '<p>Solo español</p>']]);
    // El aviso del segundo caso solo existe en español; el envío es en inglés (la convocatoria admite es y en).
    $input = subInput($ctx, ['locale' => $case === 'unpublished' ? 'es' : 'en']);

    expect(fn () => subSubmit($ctx, $input))->toThrow(CallNotReady::class)
        ->and(Submission::query()->count())->toBe(0);
})->with(['unpublished', 'missing language']);

it('CA-006-54: requires the third-party declaration only with more than one participant', function (): void {
    $ctx = subScenario();
    $two = [subParticipant('a@x.com', ['is_primary' => true]), subParticipant('b@x.com')];

    $without = subErrors($ctx, subInput($ctx, ['participants' => $two]));
    $with = subSubmit($ctx, subInput($ctx, ['participants' => $two, 'third_parties_authorized' => true]))->fresh();
    $alone = subSubmit($ctx, subInput($ctx, ['title' => 'Sola']))->fresh();

    expect(array_keys($without))->toBe(['third_parties_authorized'])
        ->and($with->third_parties_authorized_at)->not->toBeNull()
        ->and($alone->third_parties_authorized_at)->toBeNull();
});

it('CA-021-13: stores first and last name separately and computes full_name on the server', function (): void {
    $ctx = subScenario();

    $submission = subSubmit($ctx, subInput($ctx, ['participants' => [
        ['first_name' => ' Ana  ', 'last_name' => 'Pérez Gómez', 'full_name' => 'Falso Cliente', 'email' => 'ana@example.com', 'country' => 'MX', 'mobile' => '52155123456789', 'state' => 'CDMX', 'city' => 'Ciudad de México', 'phone' => '+52 55 1234 5678', 'job_title' => 'Dra.', 'company' => 'ACME', 'bio' => 'Semblanza profesional', 'is_primary' => true],
    ]]));

    $participant = $submission->participants()->sole();
    expect($participant->first_name)->toBe('Ana')
        ->and($participant->last_name)->toBe('Pérez Gómez')
        ->and($participant->full_name)->toBe('Ana Pérez Gómez')
        ->and($participant->contact->full_name)->toBe('Ana Pérez Gómez');
});

it('CA-021-13: requires both names, trimmed, within 100 characters and without control characters', function (array $names, string $errorKey): void {
    $ctx = subScenario();
    $participant = array_replace(['email' => 'a@x.com', 'country' => 'MX', 'is_primary' => true], $names);

    $errors = subErrors($ctx, subInput($ctx, ['participants' => [$participant]]));

    expect(array_keys($errors))->toContain($errorKey)
        ->and(Submission::query()->count())->toBe(0);
})->with([
    'missing first name' => [['last_name' => 'Pérez'], 'participants.0.first_name'],
    'missing last name' => [['first_name' => 'Ana'], 'participants.0.last_name'],
    'blank first name' => [['first_name' => '   ', 'last_name' => 'Pérez'], 'participants.0.first_name'],
    'blank last name' => [['first_name' => 'Ana', 'last_name' => "\n"], 'participants.0.last_name'],
    'old payload only full_name' => [['full_name' => 'Ana Pérez'], 'participants.0.first_name'],
    'newline inside' => [['first_name' => "Ana\nPérez", 'last_name' => 'Pérez'], 'participants.0.first_name'],
    'tab inside' => [['first_name' => 'Ana', 'last_name' => "Pé\trez"], 'participants.0.last_name'],
]);

it('CA-021-13: accepts names of 100 characters and keeps full_name within its column', function (): void {
    $ctx = subScenario();

    $submission = subSubmit($ctx, subInput($ctx, ['participants' => [
        ['first_name' => str_repeat('ñ', 100), 'last_name' => str_repeat('á', 100), 'email' => 'a@x.com', 'country' => 'MX', 'mobile' => '52155123456789', 'state' => 'CDMX', 'city' => 'Ciudad de México', 'phone' => '+52 55 1234 5678', 'job_title' => 'Dra.', 'company' => 'ACME', 'bio' => 'Semblanza profesional', 'is_primary' => true],
    ]]));

    expect(mb_strlen($submission->participants()->sole()->full_name))->toBe(201);
});

it('CA-021-13: leaves earlier participants with only full_name and rejects inconsistent names in the database', function (): void {
    $old = SubmissionParticipant::factory()->create();
    DB::table('submission_participants')->where('id', $old->id)->update(['first_name' => null, 'last_name' => null, 'full_name' => 'Persona Anterior']);
    $fresh = $old->fresh();

    $rejects = static function (array $names) use ($old): bool {
        DB::beginTransaction();
        try {
            DB::table('submission_participants')->where('id', $old->id)->update($names);

            return false;
        } catch (QueryException) {
            return true;
        } finally {
            DB::rollBack();
        }
    };

    expect($fresh->first_name)->toBeNull()
        ->and($fresh->last_name)->toBeNull()
        ->and($fresh->full_name)->toBe('Persona Anterior')
        ->and($rejects(['first_name' => 'Ana', 'last_name' => null]))->toBeTrue()
        ->and($rejects(['first_name' => '  ', 'last_name' => 'Pérez', 'full_name' => '   Pérez']))->toBeTrue()
        ->and($rejects(['first_name' => 'Ana', 'last_name' => 'Pérez', 'full_name' => 'Otro Nombre']))->toBeTrue()
        ->and($rejects(['first_name' => 'Ana', 'last_name' => 'Pérez', 'full_name' => 'Ana Pérez']))->toBeFalse();
});

it('CA-026-10: rejects a submission language that is no longer one of the effective languages of the call', function (): void {
    $ctx = subScenario();
    // La convocatoria sigue con es y en, pero el evento ya solo ofrece es.
    $ctx['event']->forceFill(['locales' => ['es'], 'default_locale' => 'es'])->save();

    $errors = subErrors($ctx, subInput($ctx, ['locale' => 'en']));
    $accepted = subSubmit($ctx, subInput($ctx, ['locale' => 'es']));

    expect(array_keys($errors))->toBe(['locale'])
        ->and($accepted->locale)->toBe('es');
});

it('the mobile phone accepts only numbers and exactly 14 digits', function (string $mobile, bool $accepted): void {
    $ctx = subScenario();

    $errors = subErrors($ctx, subInput($ctx, ['participants' => [subParticipant('a@x.com', ['is_primary' => true, 'mobile' => $mobile])]]));

    expect(array_key_exists('participants.0.mobile', $errors))->toBe(! $accepted);
})->with([
    'fourteen digits' => ['52155123456789', true],
    'thirteen digits' => ['5215512345678', false],
    'fifteen digits' => ['521551234567890', false],
    'with plus sign' => ['+5215512345678', false],
    'with spaces' => ['52 155 123 456 7', false],
    'letters' => ['5215512345678a', false],
]);

it('the second last name and the alternative email are optional, the rest of the participant data is required', function (): void {
    $ctx = subScenario();

    foreach (['mobile', 'state', 'city', 'phone', 'company', 'job_title', 'bio'] as $required) {
        $errors = subErrors($ctx, subInput($ctx, ['participants' => [subParticipant('a@x.com', ['is_primary' => true, $required => ''])]]));

        expect(array_key_exists("participants.0.{$required}", $errors))->toBeTrue("{$required} debe ser obligatorio");
    }

    expect(subErrors($ctx, subInput($ctx, ['participants' => [subParticipant('a@x.com', ['is_primary' => true, 'second_last_name' => '', 'alt_email' => ''])]])))->toBe([]);
});

it('stores the extra participant data and builds the full name with both last names', function (): void {
    $ctx = subScenario();

    $submission = subSubmit($ctx, subInput($ctx, ['participants' => [subParticipant('a@x.com', [
        'is_primary' => true,
        'first_name' => 'Ana',
        'last_name' => 'Pérez',
        'second_last_name' => 'Gómez',
        'alt_email' => 'ana.alterno@example.com',
    ])]]));

    $participant = $submission->participants()->sole();

    expect($participant->full_name)->toBe('Ana Pérez Gómez')
        ->and($participant->second_last_name)->toBe('Gómez')
        ->and($participant->alt_email)->toBe('ana.alterno@example.com')
        ->and($participant->mobile)->toBe('52155123456789')
        ->and($participant->state)->toBe('CDMX')
        ->and($participant->city)->toBe('Ciudad de México');
});

it('attaches the photo of a participant and links it to that participant', function (): void {
    $ctx = subScenario();
    $token = 'tok-0123456789abcdef0123456789abcdef';
    $photo = SubmissionFile::factory()->create([
        'organization_id' => $ctx['org']->id,
        'call_id' => $ctx['call']->id,
        'event_id' => $ctx['event']->id,
        'form_field_key' => 'participant_photo_0',
        'upload_token_hash' => hash('sha256', $token),
        'mime_type' => 'image/png',
    ]);

    $outcome = app(SubmitSubmission::class)->handle(
        $ctx['call'],
        subInput($ctx, ['participants' => [subParticipant('a@x.com', ['is_primary' => true, 'photo_file_id' => $photo->id])]]),
        $token,
    );

    $participant = $outcome->submission->participants()->sole();

    expect($participant->photo_file_id)->toBe($photo->id)
        ->and($photo->fresh()->status)->toBe(SubmissionFileStatus::Attached)
        ->and($photo->fresh()->submission_id)->toBe($outcome->submission->id);
});

it('refuses the photo of another participant slot or of another session', function (): void {
    $ctx = subScenario();
    $photo = SubmissionFile::factory()->create([
        'organization_id' => $ctx['org']->id,
        'call_id' => $ctx['call']->id,
        'event_id' => $ctx['event']->id,
        'form_field_key' => 'participant_photo_1',
        'upload_token_hash' => hash('sha256', 'tok-0123456789abcdef0123456789abcdef'),
    ]);

    expect(fn () => app(SubmitSubmission::class)->handle(
        $ctx['call'],
        subInput($ctx, ['participants' => [subParticipant('a@x.com', ['is_primary' => true, 'photo_file_id' => $photo->id])]]),
        'tok-0123456789abcdef0123456789abcdef',
    ))->toThrow(ValidationException::class);
});
