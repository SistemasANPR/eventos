<?php

declare(strict_types=1);

use App\Actions\Submissions\IssueFormSession;
use App\Actions\Submissions\SubmitSubmission;
use App\Enums\CallStatus;
use App\Enums\FormDefinitionStatus;
use App\Enums\OrganizationRole;
use App\Enums\SubmissionStatus;
use App\Jobs\SendTransactionalMail;
use App\Models\Activity;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Models\Track;
use App\Models\User;
use App\Support\Auth\AuthorSession;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AuthorFixtures;
use Tests\Support\FakeSubmissionForm;
use Tests\Support\Memberships;

/*
 * El autor edita su propuesta (plan 006, H-006-4, CA-006-41 a 45 y CA-006-48).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    FakeSubmissionForm::install();
    // Dentro de la ventana de la convocatoria del factory (2027-01-10 15:00 a 2027-02-15 23:00 UTC).
    $this->travelTo('2027-01-20 12:00:00');
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Convocatoria abierta con formulario publicado (resumen obligatorio, nivel opcional y un archivo), dos ejes y
 * una propuesta enviada de verdad con `SubmitSubmission`, con la sesión del autor abierta.
 *
 * @return array{org: Organization, event: Event, call: CallForSubmissions, form: FormDefinition, track: Track, other: Track, submission: Submission}
 */
function editScenario(): array
{
    $org = Memberships::organization();
    CurrentOrganization::set($org);
    $event = Event::factory()->published()->create(['organization_id' => $org->id, 'slug' => 'congreso-edicion', 'name' => ['es' => 'Congreso', 'en' => 'Congress']]);
    $field = static fn (string $key, string $type, bool $required, int $position, array $config = []): array => [
        'key' => $key, 'type' => $type, 'required' => $required, 'position' => $position, 'identifiesAuthor' => false,
        'label' => ['es' => ucfirst($key)], 'help' => [], 'config' => $config, 'retiredAt' => null,
    ];
    $form = FormDefinition::factory()->create([
        'organization_id' => $org->id,
        'event_id' => $event->id,
        'status' => FormDefinitionStatus::Published,
        'published_at' => now(),
        'version' => 3,
        'fields' => [
            $field('summary', 'long_text', true, 1, ['maxLength' => 500]),
            $field('level', 'short_text', false, 2, ['maxLength' => 50]),
        ],
    ]);
    $call = CallForSubmissions::factory()->published()->create([
        'organization_id' => $org->id,
        'event_id' => $event->id,
        'form_definition_id' => $form->id,
        'locales' => ['es', 'en'],
    ]);
    $track = Track::factory()->create(['organization_id' => $org->id, 'event_id' => $event->id]);
    $other = Track::factory()->create(['organization_id' => $org->id, 'event_id' => $event->id]);
    PrivacyNotice::factory()->published()->create(['organization_id' => $org->id, 'event_id' => $event->id]);

    $submission = app(SubmitSubmission::class)->handle($call, [
        'idempotency_key' => app(IssueFormSession::class)->handle($call)['idempotency_key'],
        'form_version' => 3,
        'locale' => 'es',
        'title' => 'Parques vivos',
        'track_id' => $track->id,
        'answers' => ['summary' => 'Un resumen', 'level' => 'Basico'],
        'participants' => [['first_name' => 'Ana', 'last_name' => 'Perez', 'email' => 'ana@example.test', 'country' => 'MX', 'state' => 'CDMX', 'city' => 'Ciudad de México', 'phone' => '+52 55 1234 5678', 'job_title' => 'Dra.', 'company' => 'ACME', 'bio' => 'Semblanza profesional', 'is_primary' => true]],
        'consent' => true,
    ])->submission;
    app(AuthorSession::class)->open($submission);

    return compact('org', 'event', 'call', 'form', 'track', 'other', 'submission');
}

/**
 * @param  array<string, mixed>  $ctx
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function editPayload(array $ctx, array $overrides = []): array
{
    return array_replace([
        'version' => 1,
        'form_version' => 3,
        'title' => 'Parques muy vivos',
        'track_id' => $ctx['other']->id,
        'answers' => ['summary' => 'Resumen nuevo', 'level' => 'Avanzado'],
        'participants' => [['first_name' => 'Ana María', 'last_name' => 'Pérez', 'country' => 'CO', 'state' => 'CDMX', 'city' => 'Ciudad de México', 'phone' => '+52 55 1234 5678', 'job_title' => 'Dra.', 'company' => 'ACME', 'bio' => 'Semblanza profesional']],
    ], $overrides);
}

/** @param  array<string, mixed>  $ctx */
function editPath(array $ctx): string
{
    return AuthorFixtures::path($ctx['submission'], 'my-submission/edit');
}

/** @return list<SendTransactionalMail> */
function updatedMails(): array
{
    return Queue::pushed(SendTransactionalMail::class, fn (SendTransactionalMail $job): bool => $job->notificationKey === 'submission.updated')->values()->all();
}

it('CA-006-41: a valid edit is saved, bumps the version, audits old and new values and queues submission.updated to the team', function (): void {
    Queue::fake();
    $ctx = editScenario();
    $organizer = Memberships::attach(User::factory()->create(['email' => 'org@example.test']), $ctx['org'], OrganizationRole::Organizer);

    $this->patch(editPath($ctx), editPayload($ctx))->assertRedirect(AuthorFixtures::path($ctx['submission']));

    $fresh = Submission::query()->findOrFail($ctx['submission']->id);
    $participant = SubmissionParticipant::query()->where('submission_id', $fresh->id)->sole();
    $audit = Activity::query()->where('event', 'submission_updated')->sole();
    expect($fresh->title)->toBe('Parques muy vivos')
        ->and($fresh->track_id)->toBe($ctx['other']->id)
        ->and($fresh->answers)->toEqual(['summary' => 'Resumen nuevo', 'level' => 'Avanzado'])
        ->and($fresh->version)->toBe(2)
        ->and($participant->full_name)->toBe('Ana María Pérez')
        ->and($participant->country)->toBe('CO')
        ->and($participant->job_title)->toBe('Dra.')
        ->and($audit->properties['title'])->toBe(['old' => 'Parques vivos', 'new' => 'Parques muy vivos'])
        ->and($audit->properties['answers_changed'])->toEqualCanonicalizing(['summary', 'level'])
        ->and((string) json_encode($audit->properties))->not->toContain('Resumen nuevo')
        ->and((string) json_encode($audit->properties))->not->toContain('ana@example.test')
        ->and(array_map(static fn (SendTransactionalMail $job): string => $job->recipient->email, updatedMails()))->toBe([$organizer->email]);
});

it('CA-006-41: the edit page offers the current values without the file fields, and the proposal page links to it', function (): void {
    $ctx = editScenario();

    $this->get(AuthorFixtures::path($ctx['submission']))->assertInertia(fn ($page) => $page->where('editUrl', AuthorFixtures::path($ctx['submission'], 'my-submission/edit')));

    $response = $this->get(editPath($ctx));
    $response->assertOk();
    $props = (array) AssertableInertia::fromTestResponse($response)->toArray()['props'];
    $edit = (array) $props['edit'];
    expect($edit['version'])->toBe(1)
        ->and($edit['formVersion'])->toBe(3)
        ->and(array_column((array) $edit['fields'], 'key'))->toBe(['s.title', 's.track_id', 'summary', 'level'])
        ->and($edit['initialValues'])->toMatchArray(['s.title' => 'Parques vivos', 's.track_id' => $ctx['track']->id, 'summary' => 'Un resumen', 's.participants.0.first_name' => 'Ana'])
        ->and(array_column((array) $props['participants'], 'email'))->toBe(['ana@example.test']);
});

it('CA-006-42: a closed, paused or not yet open call rejects the edit with CALL_NOT_OPEN and changes nothing', function (string $when, ?CallStatus $status): void {
    Queue::fake();
    $ctx = editScenario();
    if ($status !== null) {
        $ctx['call']->forceFill(['status' => $status])->save();
    }
    $this->travelTo($when === 'exact close' ? $ctx['call']->closes_at->copy() : '2027-01-20 12:00:00');
    app(AuthorSession::class)->open($ctx['submission']);

    $this->patch(editPath($ctx), editPayload($ctx))->assertSessionHasErrors('edit');

    $fresh = Submission::query()->findOrFail($ctx['submission']->id);
    expect(session('errors')->first('edit'))->toContain('CALL_NOT_OPEN')
        ->and($fresh->title)->toBe('Parques vivos')
        ->and($fresh->version)->toBe(1);
    Queue::assertNotPushed(SendTransactionalMail::class, fn (SendTransactionalMail $job): bool => $job->notificationKey === 'submission.updated');
})->with([
    'exact close' => ['exact close', null],
    'paused' => ['now', CallStatus::Paused],
    'emergency closed' => ['now', CallStatus::Closed],
]);

it('CA-006-42: one second before the close the edit is accepted', function (): void {
    Queue::fake();
    $ctx = editScenario();
    $this->travelTo($ctx['call']->closes_at->copy()->subSecond());
    app(AuthorSession::class)->open($ctx['submission']);

    $this->patch(editPath($ctx), editPayload($ctx))->assertRedirect(AuthorFixtures::path($ctx['submission']));

    expect(Submission::query()->findOrFail($ctx['submission']->id)->version)->toBe(2);
});

it('CA-006-43: a second edit with an old version is a conflict and does not overwrite the first', function (): void {
    Queue::fake();
    $ctx = editScenario();

    $this->patch(editPath($ctx), editPayload($ctx, ['title' => 'Primera edición']))->assertRedirect(AuthorFixtures::path($ctx['submission']));
    $this->patch(editPath($ctx), editPayload($ctx, ['title' => 'Segunda edición']))->assertSessionHasErrors('edit');

    $fresh = Submission::query()->findOrFail($ctx['submission']->id);
    expect(session('errors')->first('edit'))->toContain('SUBMISSION_VERSION_CONFLICT')
        ->and($fresh->title)->toBe('Primera edición')
        ->and($fresh->version)->toBe(2);
});

it('CA-006-44: answers are validated with the current definition, retired fields are not asked and the snapshot is refreshed', function (): void {
    Queue::fake();
    $ctx = editScenario();

    $this->patch(editPath($ctx), editPayload($ctx, ['answers' => ['level' => str_repeat('x', 51)]]))
        ->assertSessionHasErrors(['answers.summary', 'answers.level']);
    expect(Submission::query()->findOrFail($ctx['submission']->id)->version)->toBe(1);

    $fields = $ctx['form']->fields;
    $fields[0]['label'] = ['es' => 'Resumen corto'];
    $fields[1]['retiredAt'] = '2027-01-19T00:00:00Z';
    $ctx['form']->forceFill(['fields' => $fields])->save();

    $this->patch(editPath($ctx), editPayload($ctx, ['answers' => ['summary' => 'Listo']]))->assertRedirect();

    $fresh = Submission::query()->findOrFail($ctx['submission']->id);
    expect($fresh->answers)->toBe(['level' => 'Basico', 'summary' => 'Listo'])
        ->and($fresh->form_snapshot['fields'][0]['label'])->toBe(['es' => 'Resumen corto'])
        ->and($fresh->form_version)->toBe(3);

    $this->patch(editPath($ctx), editPayload($ctx, ['version' => 2, 'answers' => ['summary' => 'Listo', 'level' => 'No']]))
        ->assertSessionHasErrors('answers.level');
});

it('CA-006-44: a form version that is no longer the current one answers FORM_OUTDATED and saves nothing', function (): void {
    Queue::fake();
    $ctx = editScenario();
    $ctx['form']->forceFill(['version' => 4])->save();

    $this->patch(editPath($ctx), editPayload($ctx))->assertSessionHasErrors('edit');

    expect(session('errors')->first('edit'))->toContain('FORM_OUTDATED')
        ->and(Submission::query()->findOrFail($ctx['submission']->id)->title)->toBe('Parques vivos');
});

it('CA-006-45: the primary email, status, call and organization cannot be changed by the author', function (): void {
    Queue::fake();
    $ctx = editScenario();
    $stranger = Organization::factory()->create();
    $payload = editPayload($ctx, [
        'status' => 'accepted',
        'organization_id' => $stranger->id,
        'call_for_submissions_id' => (string) Str::uuid(),
        'event_id' => (string) Str::uuid(),
        'participants' => [['first_name' => 'Ana', 'last_name' => 'Perez', 'country' => 'MX', 'state' => 'CDMX', 'city' => 'Ciudad de México', 'phone' => '+52 55 1234 5678', 'job_title' => 'Dra.', 'company' => 'ACME', 'bio' => 'Semblanza profesional', 'email' => 'secuestro@example.test', 'is_primary' => false]],
    ]);

    $this->patch(editPath($ctx), $payload)->assertRedirect(AuthorFixtures::path($ctx['submission']));

    $fresh = Submission::query()->findOrFail($ctx['submission']->id);
    $participant = SubmissionParticipant::query()->where('submission_id', $fresh->id)->sole();
    expect($participant->email)->toBe('ana@example.test')
        ->and($participant->is_primary)->toBeTrue()
        ->and($fresh->status)->toBe(SubmissionStatus::Submitted)
        ->and($fresh->organization_id)->toBe($ctx['org']->id)
        ->and($fresh->call_for_submissions_id)->toBe($ctx['call']->id)
        ->and($fresh->event_id)->toBe($ctx['event']->id);
});

it('CA-006-8: the track must be an active track of the same event', function (): void {
    Queue::fake();
    $ctx = editScenario();
    $foreign = Track::factory()->create(['organization_id' => $ctx['org']->id, 'event_id' => Event::factory()->published()->create(['organization_id' => $ctx['org']->id])->id]);

    $this->patch(editPath($ctx), editPayload($ctx, ['track_id' => $foreign->id]))->assertSessionHasErrors('track_id');

    expect(Submission::query()->findOrFail($ctx['submission']->id)->track_id)->toBe($ctx['track']->id);
});

it('CA-006-48: a withdrawn proposal cannot be edited and its edit page sends the author back', function (): void {
    Queue::fake();
    $ctx = editScenario();
    $ctx['submission']->forceFill(['status' => SubmissionStatus::Withdrawn, 'withdrawn_at' => now()])->save();

    $this->patch(editPath($ctx), editPayload($ctx))->assertSessionHasErrors('edit');
    expect(session('errors')->first('edit'))->toContain('SUBMISSION_NOT_EDITABLE');

    $this->get(editPath($ctx))->assertRedirect(AuthorFixtures::path($ctx['submission']));
    expect(Submission::query()->findOrFail($ctx['submission']->id)->title)->toBe('Parques vivos');
});

it('CA-006-38: edit routes need the author session and another proposal is never reachable', function (): void {
    Queue::fake();
    $ctx = editScenario();
    $other = AuthorFixtures::submission();
    app(AuthorSession::class)->open($ctx['submission']);

    $this->get(AuthorFixtures::path($other, 'my-submission/edit'))->assertNotFound();
    $this->patch(AuthorFixtures::path($other, 'my-submission/edit'), editPayload($ctx))->assertNotFound();
    expect(Submission::query()->withoutGlobalScopes()->findOrFail($other->id)->title)->toBe('Parques vivos');
});

it('CA-006-37: without an author session the edit routes ask for a new link', function (): void {
    $submission = AuthorFixtures::submission();
    $requestLink = AuthorFixtures::path($submission, 'my-submission/request-link');

    $this->get(AuthorFixtures::path($submission, 'my-submission/edit'))->assertRedirect($requestLink);
    $this->patch(AuthorFixtures::path($submission, 'my-submission/edit'), ['title' => 'x'])->assertRedirect($requestLink);
});

/*
 * Plan 034: la vista del autor dice si puede editar, hasta cuándo y, si no, por qué (CA-034-14).
 */

it('CA-034-14: with an open call the author sees it is editable until the closing, in the event timezone and time format', function (): void {
    $ctx = editScenario();
    $closing = $ctx['call']->closes_at->utc()->format('Y-m-d\TH:i:s\Z');

    $this->get(AuthorFixtures::path($ctx['submission']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Author/Show')
            ->where('editPolicy.state', 'editable')
            ->where('editPolicy.until', $closing)
            ->where('timezone', $ctx['event']->timezone)
            ->where('timeFormat', $ctx['event']->time_format->value)
            ->where('logoutUrl', AuthorFixtures::path($ctx['submission'], 'my-submission/logout'))
            ->where('editUrl', fn (?string $url): bool => $url !== null));
});

it('CA-034-14: after the closing the state is call_closed with the closing date and there is no edit button', function (): void {
    $ctx = editScenario();
    $closing = $ctx['call']->closes_at->utc()->format('Y-m-d\TH:i:s\Z');
    $this->travelTo($ctx['call']->closes_at->copy()->addHour());
    app(AuthorSession::class)->open($ctx['submission']);

    $this->get(AuthorFixtures::path($ctx['submission']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('editPolicy.state', 'call_closed')
            ->where('editPolicy.until', $closing)
            ->where('editUrl', null));
});

it('CA-034-14: a proposal that is no longer submitted is in_process, without edit button and without a date', function (SubmissionStatus $status): void {
    $ctx = editScenario();
    $ctx['submission']->forceFill(['status' => $status, 'withdrawn_at' => $status === SubmissionStatus::Withdrawn ? now() : null])->save();

    $this->get(AuthorFixtures::path($ctx['submission']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('editPolicy.state', 'in_process')
            ->where('editPolicy.until', null)
            ->where('editUrl', null));
})->with([SubmissionStatus::UnderReview, SubmissionStatus::Accepted, SubmissionStatus::Withdrawn]);
