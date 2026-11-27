<?php

declare(strict_types=1);

use App\Actions\Submissions\IssueFormSession;
use App\Actions\Submissions\SubmitSubmission;
use App\Enums\FormDefinitionStatus;
use App\Enums\OrganizationRole;
use App\Jobs\SendTransactionalMail;
use App\Mail\OutgoingMailable;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\MailTemplate;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Models\Track;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSubmissionForm;
use Tests\Support\Memberships;

/*
 * Correos de un envío confirmado (plan 006, CA-006-29): siempre por `SendNotification`, encolados.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    FakeSubmissionForm::install();
    $this->travelTo('2027-01-20 12:00:00');
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array{org: Organization, call: CallForSubmissions, track: Track, event: Event} */
function notifyScenario(): array
{
    $org = Memberships::organization();
    CurrentOrganization::set($org);
    $event = Event::factory()->published()->create(['organization_id' => $org->id, 'name' => ['es' => 'Congreso', 'en' => 'Congress']]);
    $form = FormDefinition::factory()->create([
        'organization_id' => $org->id,
        'event_id' => $event->id,
        'status' => FormDefinitionStatus::Published,
        'published_at' => now(),
        'version' => 1,
        'fields' => [],
    ]);
    $call = CallForSubmissions::factory()->published()->create([
        'organization_id' => $org->id,
        'event_id' => $event->id,
        'form_definition_id' => $form->id,
        'locales' => ['es', 'en'],
    ]);
    $track = Track::factory()->create(['organization_id' => $org->id, 'event_id' => $event->id]);
    PrivacyNotice::factory()->published()->create(['organization_id' => $org->id, 'event_id' => $event->id]);

    return compact('org', 'call', 'track', 'event');
}

/**
 * @param  array<string, mixed>  $ctx
 * @return array<string, mixed>
 */
function notifyInput(array $ctx): array
{
    return [
        'idempotency_key' => app(IssueFormSession::class)->handle($ctx['call'])['idempotency_key'],
        'form_version' => 1,
        'locale' => 'es',
        'title' => 'Parques vivos',
        'track_id' => $ctx['track']->id,
        'answers' => [],
        'participants' => [['first_name' => 'Ana', 'last_name' => 'Pérez', 'email' => 'ana@example.test', 'country' => 'MX', 'state' => 'CDMX', 'city' => 'Ciudad de México', 'phone' => '+52 55 1234 5678', 'job_title' => 'Dra.', 'company' => 'ACME', 'bio' => 'Semblanza profesional', 'is_primary' => true]],
        'consent' => true,
    ];
}

/** @return list<SendTransactionalMail> */
function pushedMails(string $key): array
{
    return Queue::pushed(SendTransactionalMail::class, fn (SendTransactionalMail $job): bool => $job->notificationKey === $key)->values()->all();
}

it('CA-006-29: queues the acknowledgement to the primary participant without an access link, and the organizer notice', function (): void {
    Queue::fake();
    $ctx = notifyScenario();
    $organizer = Memberships::attach(User::factory()->create(['email' => 'org@example.test']), $ctx['org'], OrganizationRole::Organizer);
    $reviewer = Memberships::attach(User::factory()->create(['email' => 'rev@example.test']), $ctx['org'], OrganizationRole::Reviewer);
    $other = Memberships::attach(User::factory()->create(['email' => 'otra@example.test']), Memberships::organization(), OrganizationRole::OrgAdmin);

    $submission = app(SubmitSubmission::class)->handle($ctx['call'], notifyInput($ctx))->submission;

    $received = pushedMails('submission.received');
    expect($received)->toHaveCount(1)
        ->and($received[0]->recipient->email)->toBe('ana@example.test')
        ->and($received[0]->variables)->not->toHaveKey('access_url')
        ->and(implode(' ', $received[0]->variables))->not->toContain('my-submission/access')
        ->and($received[0]->variables['submission_title'])->toBe('Parques vivos');

    $notices = pushedMails('submission.new_for_organizers');
    $emails = array_map(static fn (SendTransactionalMail $job): string => $job->recipient->email, $notices);
    expect($emails)->toBe([$organizer->email])
        ->and($emails)->not->toContain($reviewer->email)
        ->and($emails)->not->toContain($other->email)
        ->and($notices[0]->variables['panel_url'])->toContain('/admin/'.$ctx['org']->slug.'/events/'.$ctx['event']->id.'/submissions')
        ->and(Submission::query()->whereKey($submission->id)->exists())->toBeTrue();
});

it('CA-006-29: every organizer with submissions.view gets their own notice', function (): void {
    Queue::fake();
    $ctx = notifyScenario();
    Memberships::attach(User::factory()->create(['email' => 'a@example.test']), $ctx['org'], OrganizationRole::Organizer);
    Memberships::attach(User::factory()->create(['email' => 'b@example.test']), $ctx['org'], OrganizationRole::OrgAdmin);

    app(SubmitSubmission::class)->handle($ctx['call'], notifyInput($ctx));

    $emails = array_map(static fn (SendTransactionalMail $job): string => $job->recipient->email, pushedMails('submission.new_for_organizers'));
    sort($emails);
    expect($emails)->toBe(['a@example.test', 'b@example.test']);
});

it('CA-006-9: queues no email when the transaction rolls back', function (): void {
    Queue::fake();
    $ctx = notifyScenario();
    Memberships::attach(User::factory()->create(), $ctx['org'], OrganizationRole::Organizer);
    EventBus::listen('eloquent.created: '.SubmissionParticipant::class, function (): void {
        throw new RuntimeException('fallo simulado');
    });

    try {
        expect(fn () => app(SubmitSubmission::class)->handle($ctx['call'], notifyInput($ctx)))->toThrow(RuntimeException::class);
    } finally {
        EventBus::forget('eloquent.created: '.SubmissionParticipant::class);
    }

    Queue::assertNotPushed(SendTransactionalMail::class);
    expect(Submission::query()->count())->toBe(0);
});

it('CA-006-14: a repeated submission with the same key queues the emails only once', function (): void {
    Queue::fake();
    $ctx = notifyScenario();
    Memberships::attach(User::factory()->create(), $ctx['org'], OrganizationRole::Organizer);
    $input = notifyInput($ctx);

    app(SubmitSubmission::class)->handle($ctx['call'], $input);
    app(SubmitSubmission::class)->handle($ctx['call'], $input);

    expect(pushedMails('submission.received'))->toHaveCount(1)
        ->and(pushedMails('submission.new_for_organizers'))->toHaveCount(1);
});

/*
 * Plan 034: el acuse avisa que se puede editar y hasta cuándo, con la dirección de pedir acceso (CA-034-8 a 11).
 */

it('CA-034-8: the acknowledgement carries the request-link page and the edit deadline, never an access token', function (): void {
    Queue::fake();
    $ctx = notifyScenario();

    app(SubmitSubmission::class)->handle($ctx['call'], notifyInput($ctx));

    $variables = pushedMails('submission.received')[0]->variables;
    expect($variables['request_link_url'])->toBe(route('author.request-link.show', ['organization' => $ctx['org']->slug, 'event' => $ctx['event']->slug]))
        ->and($variables['edit_deadline'])->not->toBe('')
        ->and($variables)->not->toHaveKey('access_url')
        ->and(implode(' ', $variables))->not->toContain('my-submission/access');
});

it('CA-034-9: the deadline is the closing in the event timezone and the language of the submission', function (string $locale, string $expected): void {
    Queue::fake();
    $ctx = notifyScenario();
    // 2027-02-16 02:00 UTC es todavía el 15 de febrero a las 20:00 en la Ciudad de México.
    $ctx['event']->forceFill(['timezone' => 'America/Mexico_City'])->save();
    $ctx['call']->forceFill(['closes_at' => '2027-02-16 02:00:00', 'results_at' => '2027-03-01 00:00:00'])->save();

    app(SubmitSubmission::class)->handle($ctx['call'], ['locale' => $locale] + notifyInput($ctx));

    expect(pushedMails('submission.received')[0]->variables['edit_deadline'])->toBe($expected);
})->with([
    ['es', '15 de febrero de 2027, 20:00 CST'],
    ['en', 'February 15, 2027, 20:00 CST'],
]);

it('CA-034-10: a template customized by the organizer without the new variables still sends', function (): void {
    Queue::fake();
    Mail::fake();
    $ctx = notifyScenario();
    MailTemplate::factory()->create([
        'organization_id' => $ctx['org']->id,
        'notification_key' => 'submission.received',
        'locale' => 'es',
        'subject' => 'Recibido: {{ submission_title }}',
        'body' => 'Hola {{ author_name }}, recibimos tu propuesta.',
    ]);

    app(SubmitSubmission::class)->handle($ctx['call'], notifyInput($ctx));
    app()->call([pushedMails('submission.received')[0], 'handle']);

    Mail::assertSent(OutgoingMailable::class, fn (OutgoingMailable $mail): bool => $mail->subject === 'Recibido: Parques vivos');
});

it('CA-034-11: the default template exists in es and en and mentions the edit deadline and the request-link page', function (string $locale): void {
    $template = trans('mail_templates.submission.received', [], $locale);

    expect($template['body'])->toContain('{{ edit_deadline }}')->and($template['body'])->toContain('{{ request_link_url }}');
})->with(['es', 'en']);
