<?php

declare(strict_types=1);

use App\Actions\Notifications\RenderMailTemplate;
use App\Actions\Submissions\SendClosingReminders;
use App\Enums\CallStatus;
use App\Enums\FormDefinitionStatus;
use App\Enums\SubmissionStatus;
use App\Jobs\SendTransactionalMail;
use App\Models\CallForSubmissions;
use App\Models\FormDefinition;
use App\Models\NotificationSetting;
use App\Models\Organization;
use App\Models\Submission;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AuthorFixtures;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

/*
 * Plan 012, F3, H-012-4: recordatorio de cierre de la convocatoria (CA-012-23 a 26). El evento de prueba
 * está en America/Mexico_City (UTC-6 en febrero) y la convocatoria cierra el 15-feb-2027 a las 23:00 UTC,
 * es decir, a las 17:00 del 15 en la zona del evento. Se avisa desde 3 días de calendario antes.
 */

beforeEach(function (): void {
    $this->travelTo('2027-02-12 15:00:00');
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Convocatoria publicada de una organización nueva con una propuesta `submitted` de `ana@example.test`.
 *
 * @param  array<string, mixed>  $call  atributos de la convocatoria
 * @return array{0: Organization, 1: CallForSubmissions, 2: Submission}
 */
function reminderScenario(array $call = []): array
{
    $organization = Memberships::organization();
    $submission = AuthorFixtures::submission($organization, ['status' => SubmissionStatus::Submitted], ['email' => 'ana@example.test']);
    CurrentOrganization::run($organization, fn () => CallForSubmissions::query()->whereKey($submission->call_for_submissions_id)
        ->update(['status' => CallStatus::Published, ...$call]));
    $callModel = CurrentOrganization::run($organization, fn (): CallForSubmissions => CallForSubmissions::query()->findOrFail($submission->call_for_submissions_id));

    return [$organization, $callModel, $submission];
}

/** @return list<SendTransactionalMail> */
function reminderMails(): array
{
    return Queue::pushed(SendTransactionalMail::class, fn (SendTransactionalMail $job): bool => $job->notificationKey === 'call.closing_reminder')->values()->all();
}

function runReminders(Organization $organization, ?string $at = null): void
{
    app(SendClosingReminders::class)->handle($organization, $at === null ? null : CarbonImmutable::parse($at, 'UTC'));
}

it('CA-012-23: every author with a submitted proposal gets one reminder when the call is 3 days from closing', function (): void {
    Queue::fake();
    [$organization, , $first] = reminderScenario();
    AuthorFixtures::sibling($first, 'beto@example.test', ['status' => SubmissionStatus::Submitted]);
    // Misma autora con una segunda propuesta (con otra capitalización del correo): un solo recordatorio.
    AuthorFixtures::sibling($first, 'ANA@example.test', ['status' => SubmissionStatus::Submitted]);

    runReminders($organization);

    $recipients = array_map(static fn (SendTransactionalMail $job): string => strtolower($job->recipient->email), reminderMails());
    sort($recipients);
    expect($recipients)->toBe(['ana@example.test', 'beto@example.test']);
});

it('CA-012-23: running the task twice sends nothing new', function (): void {
    Queue::fake();
    [$organization] = reminderScenario();

    runReminders($organization);
    runReminders($organization);
    $this->artisan('calls:send-closing-reminders')->assertSuccessful();

    expect(reminderMails())->toHaveCount(1);
});

it('CA-012-23: the scheduled command runs each organization in its own context', function (): void {
    Queue::fake();
    [$organizationA] = reminderScenario();
    [$organizationB] = reminderScenario();

    $this->artisan('calls:send-closing-reminders')->assertSuccessful();

    $byOrganization = array_map(static fn (SendTransactionalMail $job): string => $job->organizationId, reminderMails());
    sort($byOrganization);
    $expected = [$organizationA->id, $organizationB->id];
    sort($expected);
    expect($byOrganization)->toBe($expected);
});

it('CA-012-23: the reminder is not sent when it is more than 3 days from closing', function (): void {
    Queue::fake();
    [$organization] = reminderScenario();

    runReminders($organization, '2027-02-11 15:00:00');

    expect(reminderMails())->toBeEmpty();
});

it('CA-012-24: a withdrawn or decided proposal gets no reminder', function (): void {
    Queue::fake();
    [$organization, , $first] = reminderScenario();
    CurrentOrganization::run($organization, fn () => Submission::query()->whereKey($first->id)->update(['status' => SubmissionStatus::Withdrawn, 'withdrawn_at' => now()]));
    AuthorFixtures::sibling($first, 'beto@example.test', ['status' => SubmissionStatus::Accepted]);
    AuthorFixtures::sibling($first, 'carla@example.test', ['status' => SubmissionStatus::UnderReview]);

    runReminders($organization);

    expect(reminderMails())->toBeEmpty();
});

it('CA-012-24: a call that already closed, or is not open, gets no reminder', function (array $call): void {
    Queue::fake();
    [$organization] = reminderScenario($call);

    runReminders($organization);

    expect(reminderMails())->toBeEmpty();
})->with([
    'closed by time' => [['closes_at' => '2027-02-12 14:00:00']],
    'closed by a person' => [['status' => CallStatus::Closed]],
    'paused' => [['status' => CallStatus::Paused]],
    'draft' => [['status' => CallStatus::Draft]],
]);

it('CA-012-25: the days left are counted in the event time zone over the stored UTC instant', function (): void {
    Queue::fake();
    // Cierra el 16-feb 03:00 UTC = 15-feb 21:00 en la Ciudad de México.
    [$organization] = reminderScenario(['closes_at' => '2027-02-16 03:00:00']);

    // 11-feb 22:00 UTC = 11-feb 16:00 local: faltan 4 días de calendario locales (no 5 ni 4 por UTC): aún no.
    runReminders($organization, '2027-02-11 22:00:00');
    expect(reminderMails())->toBeEmpty();

    // 12-feb 22:00 UTC = 12-feb 16:00 local: faltan 3 locales (por UTC serían 4: no avisaría).
    runReminders($organization, '2027-02-12 22:00:00');
    expect(reminderMails())->toHaveCount(1);
});

it('CA-012-25: the closing date in the mail is the event local time, not UTC', function (): void {
    Queue::fake();
    [$organization] = reminderScenario(['closes_at' => '2027-02-16 03:00:00']);

    runReminders($organization, '2027-02-12 22:00:00');

    expect(reminderMails()[0]->variables['closing_date'])->toContain('15 de febrero de 2027, 21:00');
});

it('CA-012-26: without the author edit available the reminder is informative: edit_hint is empty and the text does not invite to edit', function (): void {
    Queue::fake();
    // La convocatoria no tiene un formulario publicado: la edición por el autor no está disponible.
    [$organization] = reminderScenario(['form_definition_id' => null]);

    runReminders($organization);

    $mail = reminderMails()[0];
    expect($mail->variables['edit_hint'])->toBe('');
    $rendered = app(RenderMailTemplate::class)->handle('call.closing_reminder', 'es', $mail->variables);
    expect($rendered->text)->not->toContain('corregir')->and($rendered->text)->not->toContain('editar')
        ->and($rendered->html)->toContain('request-link')->and($rendered->html)->not->toContain('/access/');
});

it('CA-012-26: with the author edit available edit_hint invites to fix the proposal', function (): void {
    Queue::fake();
    [$organization, $call, $submission] = reminderScenario();
    CurrentOrganization::run($organization, function () use ($call, $submission): void {
        FormDefinition::query()->whereKey($submission->form_definition_id)->update(['status' => FormDefinitionStatus::Published, 'published_at' => now()]);
        CallForSubmissions::query()->whereKey($call->id)->update(['form_definition_id' => $submission->form_definition_id]);
    });

    runReminders($organization);

    $hint = reminderMails()[0]->variables['edit_hint'];
    expect($hint)->toContain('corregir tu propuesta')
        ->and(reminderMails()[0]->variables['request_link_url'])->toContain('/my-submission/request-link');
});

it('CA-012-23: the reminder goes out in the language of the author', function (): void {
    Queue::fake();
    [$organization, , $submission] = reminderScenario();
    CurrentOrganization::run($organization, fn () => Submission::query()->whereKey($submission->id)->update(['locale' => 'en']));

    runReminders($organization);

    expect(reminderMails()[0]->recipient->locale)->toBe('en')
        ->and(reminderMails()[0]->variables['closing_date'])->toContain('February 15, 2027');
});

it('CA-012-23: an organization that turned the reminder off sends nothing and keeps a visible record', function (): void {
    Queue::fake();
    Mail::fake();
    [$organization] = reminderScenario();
    NotificationSetting::factory()->create(['organization_id' => $organization->id, 'notification_key' => 'call.closing_reminder', 'is_enabled' => false]);

    runReminders($organization);

    expect(reminderMails())->toBeEmpty();
    Mail::assertNothingSent();
});
