<?php

declare(strict_types=1);

use App\Actions\Notifications\RenderMailTemplate;
use App\Actions\Notifications\SetNotificationEnabled;
use App\Actions\Submissions\AcceptSubmission;
use App\Actions\Submissions\PublishResults;
use App\Actions\Submissions\PublishSubmissionResult;
use App\Actions\Submissions\RejectSubmission;
use App\Actions\Submissions\RevertSubmissionDecision;
use App\Actions\Submissions\WaitlistSubmission;
use App\Enums\MailDispatchStatus;
use App\Enums\OrganizationRole;
use App\Enums\SubmissionStatus;
use App\Exceptions\Domain\CriticalNotificationCannotBeDisabled;
use App\Jobs\SendTransactionalMail;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\MailDispatch;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Models\User;
use App\Support\Notifications\OutgoingMail;
use App\Support\Notifications\TransactionalMailer;
use App\Support\Submissions\AuthorSubmissionData;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\AuthorFixtures;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

/*
 * Plan 012, F2 y F3, H-012-3: publicar los resultados de la convocatoria y avisar al autor (CA-012-14 a 22).
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Organización con administrador, organizador y evaluador; una convocatoria con sus propuestas en el estado
 * pedido, cada una con su autor (autor0@, autor1@...).
 *
 * @param  list<SubmissionStatus>  $statuses
 * @return array{0: Organization, 1: CallForSubmissions, 2: list<Submission>, 3: User, 4: User, 5: User}
 */
function resultsScenario(array $statuses = [SubmissionStatus::Accepted]): array
{
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);

    $first = AuthorFixtures::submission($organization, ['status' => $statuses[0], 'author_comment' => null], ['email' => 'autor0@example.test']);
    $submissions = [$first];

    foreach (array_slice($statuses, 1) as $index => $status) {
        $submissions[] = AuthorFixtures::sibling($first, 'autor'.($index + 1).'@example.test', ['status' => $status]);
    }

    $call = CurrentOrganization::run($organization, fn (): CallForSubmissions => CallForSubmissions::query()->findOrFail($first->call_for_submissions_id));

    return [$organization, $call, $submissions, $admin, $organizer, $reviewer];
}

/** @return list<SendTransactionalMail> */
function resultMails(?string $key = null): array
{
    return Queue::pushed(SendTransactionalMail::class, fn (SendTransactionalMail $job): bool => $key === null || $job->notificationKey === $key)->values()->all();
}

function resultStored(Organization $organization, Submission $submission): Submission
{
    return CurrentOrganization::run($organization, fn (): Submission => Submission::query()->findOrFail($submission->id));
}

function resultCall(Organization $organization, CallForSubmissions $call): CallForSubmissions
{
    return CurrentOrganization::run($organization, fn (): CallForSubmissions => CallForSubmissions::query()->findOrFail($call->id));
}

it('CA-012-14: publishing sets results_published_at and queues the result mail to the author, with the comment and without any token', function (): void {
    Queue::fake();
    [$organization, $call, $submissions, , $organizer] = resultsScenario([SubmissionStatus::UnderReview]);
    app(AcceptSubmission::class)->handle($organizer, $submissions[0], 'Nos encanto <b>tu</b> propuesta');
    expect(resultMails())->toBeEmpty();

    $report = app(PublishResults::class)->handle($organizer, $call);

    expect($report->published)->toBe(1)->and($report->failed)->toBe(0)->and($report->alreadyPublished)->toBeFalse()
        ->and(resultCall($organization, $call)->results_published_at)->not->toBeNull()
        ->and(resultStored($organization, $submissions[0])->results_published_at)->not->toBeNull();

    $mails = resultMails('submission.accepted');
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->recipient->email)->toBe('autor0@example.test')
        ->and($mails[0]->variables['author_comment'])->toContain('Nos encanto tu propuesta')
        ->and($mails[0]->variables)->not->toHaveKey('access_url')
        ->and($mails[0]->variables['request_link_url'])->toContain('/my-submission/request-link')
        ->and($mails[0]->variables['request_link_url'])->not->toContain('/access/');

    $html = app(RenderMailTemplate::class)->handle('submission.accepted', 'es', $mails[0]->variables)->html;
    expect($html)->toContain('request-link')->and($html)->not->toContain('/access/')->and($html)->not->toContain('<b>');

    $audit = Activity::query()->where('event', 'call_results_published')->sole();
    expect($audit->causer_id)->toBe($organizer->id)->and($audit->properties['call_id'])->toBe($call->id);
});

it('CA-012-14: each decision sends its own mail (accepted, waitlisted, rejected) and an undecided proposal none', function (): void {
    Queue::fake();
    [, $call, , , $organizer] = resultsScenario([SubmissionStatus::Accepted, SubmissionStatus::Waitlisted, SubmissionStatus::Rejected, SubmissionStatus::UnderReview, SubmissionStatus::Submitted]);

    $report = app(PublishResults::class)->handle($organizer, $call);

    expect($report->published)->toBe(3)
        ->and(resultMails('submission.accepted'))->toHaveCount(1)
        ->and(resultMails('submission.waitlisted'))->toHaveCount(1)
        ->and(resultMails('submission.rejected'))->toHaveCount(1)
        ->and(resultMails())->toHaveCount(3);
});

it('CA-012-27: the mail carries the comment only when there is one', function (): void {
    Queue::fake();
    [, $call, , , $organizer] = resultsScenario([SubmissionStatus::Accepted]);

    app(PublishResults::class)->handle($organizer, $call);

    expect(resultMails('submission.accepted')[0]->variables['author_comment'])->toBe('');
});

it('CA-012-15: publishing again or retrying never sends a second mail to the same author for the same result', function (): void {
    Queue::fake();
    [$organization, $call, $submissions, , $organizer] = resultsScenario([SubmissionStatus::Accepted]);

    app(PublishResults::class)->handle($organizer, $call);
    $again = app(PublishResults::class)->handle($organizer, $call);

    expect($again->published)->toBe(0)->and($again->alreadyPublished)->toBeTrue();
    expect(resultMails())->toHaveCount(1);

    // Un reintento que ve la propuesta sin publicar tampoco duplica: la clave de idempotencia es la propuesta y el resultado.
    CurrentOrganization::run($organization, fn () => Submission::query()->whereKey($submissions[0]->id)->update(['results_published_at' => null]));
    app(PublishResults::class)->handle($organizer, $call);

    expect(resultMails())->toHaveCount(1)
        ->and(CurrentOrganization::run($organization, fn (): int => MailDispatch::query()->where('notification_key', 'submission.accepted')->count()))->toBe(1);
});

it('CA-012-16: before publishing the author sees the proposal in review, after publishing the result', function (): void {
    Queue::fake();
    [$organization, $call, $submissions, , $organizer] = resultsScenario([SubmissionStatus::UnderReview]);
    app(AcceptSubmission::class)->handle($organizer, $submissions[0]);

    $view = fn (): string => CurrentOrganization::run($organization, function () use ($submissions): string {
        $submission = Submission::query()->with(['participants', 'track'])->findOrFail($submissions[0]->id);
        $event = Event::query()->findOrFail($submission->event_id);

        return AuthorSubmissionData::from($submission, $event, 'es')['status'];
    });

    expect($view())->toBe('under_review');

    app(PublishResults::class)->handle($organizer, $call);

    expect($view())->toBe('accepted');
});

it('CA-012-16: reverting a published decision shows the author the proposal in review again', function (): void {
    Queue::fake();
    [$organization, $call, $submissions, $admin] = resultsScenario([SubmissionStatus::Rejected]);
    app(PublishResults::class)->handle($admin, $call);

    app(RevertSubmissionDecision::class)->handle($admin, resultStored($organization, $submissions[0]), confirmedPublished: true);

    $status = CurrentOrganization::run($organization, function () use ($submissions): string {
        $submission = Submission::query()->with(['participants', 'track'])->findOrFail($submissions[0]->id);

        return AuthorSubmissionData::from($submission, Event::query()->findOrFail($submission->event_id), 'es')['status'];
    });
    expect($status)->toBe('under_review');
});

it('CA-012-18: a provider failure leaves the dispatch visibly failed and the decision untouched', function (): void {
    Queue::fake();
    [$organization, $call, $submissions, , $organizer] = resultsScenario([SubmissionStatus::Accepted]);
    app(PublishResults::class)->handle($organizer, $call);

    app()->bind(TransactionalMailer::class, fn () => new class implements TransactionalMailer
    {
        public function send(OutgoingMail $mail): void
        {
            throw new RuntimeException('proveedor caido');
        }
    });

    $job = resultMails('submission.accepted')[0];
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);
    $job->failed(new RuntimeException('proveedor caido'));

    $dispatch = CurrentOrganization::run($organization, fn (): MailDispatch => MailDispatch::query()->where('notification_key', 'submission.accepted')->sole());
    expect($dispatch->status)->toBe(MailDispatchStatus::Failed)
        ->and($job->tries)->toBe(5)
        ->and(resultStored($organization, $submissions[0])->status)->toBe(SubmissionStatus::Accepted);
});

it('CA-012-18: a failure publishing one author is reported, keeps the decision and the other authors still get theirs', function (): void {
    Queue::fake();
    // La segunda propuesta no tiene participante principal: publicar su resultado falla (dato roto, no se oculta).
    [$organization, $call, $submissions, , $organizer] = resultsScenario([SubmissionStatus::Accepted]);
    $broken = AuthorFixtures::sibling($submissions[0], 'sinprincipal@example.test', ['status' => SubmissionStatus::Rejected], primary: false);

    $report = app(PublishResults::class)->handle($organizer, $call);

    expect($report->failed)->toBe(1)->and($report->published)->toBe(1)
        ->and(resultCall($organization, $call)->results_published_at)->not->toBeNull()
        ->and(resultMails())->toHaveCount(1);
    // La propuesta cuyo aviso falló queda sin publicar (se reintenta) y su decisión sigue en pie.
    expect(resultStored($organization, $broken)->results_published_at)->toBeNull()
        ->and(resultStored($organization, $broken)->status)->toBe(SubmissionStatus::Rejected);

    // Arreglado el dato, al publicar de nuevo se avisa a quien faltaba y no se repite al que ya se avisó.
    CurrentOrganization::run($organization, fn () => SubmissionParticipant::query()->where('submission_id', $broken->id)->update(['is_primary' => true]));
    $retry = app(PublishResults::class)->handle($organizer, $call);

    expect($retry->published)->toBe(1)->and($retry->failed)->toBe(0)->and(resultMails())->toHaveCount(2);
});

it('CA-012-19: a user without submissions.decide cannot publish results: no state change and no mail', function (): void {
    Queue::fake();
    Mail::fake();
    [$organization, $call, $submissions, , , $reviewer] = resultsScenario([SubmissionStatus::Accepted]);

    expect(fn () => app(PublishResults::class)->handle($reviewer, $call))->toThrow(AuthorizationException::class);

    expect(resultCall($organization, $call)->results_published_at)->toBeNull()
        ->and(resultStored($organization, $submissions[0])->results_published_at)->toBeNull()
        ->and(resultMails())->toBeEmpty();
    Mail::assertNothingSent();
});

it('CA-012-20: without a results date, deciding sends nothing until someone publishes manually', function (): void {
    Queue::fake();
    [$organization, $call, $submissions, , $organizer] = resultsScenario([SubmissionStatus::UnderReview, SubmissionStatus::UnderReview, SubmissionStatus::UnderReview]);
    expect($call->results_at)->toBeNull();

    app(AcceptSubmission::class)->handle($organizer, $submissions[0]);
    app(RejectSubmission::class)->handle($organizer, $submissions[1]);
    app(WaitlistSubmission::class)->handle($organizer, $submissions[2]);

    expect(resultMails())->toBeEmpty()
        ->and(resultStored($organization, $submissions[0])->results_published_at)->toBeNull();

    app(PublishResults::class)->handle($organizer, $call);

    expect(resultMails())->toHaveCount(3);
});

it('CA-012-20: even with a results date in the past nothing is sent until the manual publication', function (): void {
    Queue::fake();
    [$organization, $call, $submissions, , $organizer] = resultsScenario([SubmissionStatus::UnderReview]);
    CurrentOrganization::run($organization, fn () => CallForSubmissions::query()->whereKey($call->id)->update(['results_at' => '2027-02-20 12:00:00']));
    $this->travelTo('2027-03-01 12:00:00');

    app(AcceptSubmission::class)->handle($organizer, $submissions[0]);

    expect(resultMails())->toBeEmpty();
});

it('CA-012-21: once the call published its results, a new decision queues its mail at decision time, once', function (): void {
    Queue::fake();
    [$organization, $call, $submissions, $admin, $organizer] = resultsScenario([SubmissionStatus::UnderReview, SubmissionStatus::UnderReview]);
    app(PublishResults::class)->handle($organizer, $call);
    expect(resultMails())->toBeEmpty();

    app(AcceptSubmission::class)->handle($organizer, $submissions[0], 'Bienvenida');

    $mails = resultMails('submission.accepted');
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->recipient->email)->toBe('autor0@example.test')
        ->and($mails[0]->variables['author_comment'])->toContain('Bienvenida')
        ->and(resultStored($organization, $submissions[0])->results_published_at)->not->toBeNull();

    // Una segunda llamada (por ejemplo un reintento) no duplica el correo.
    expect(app(PublishSubmissionResult::class)->afterDecision(resultStored($organization, $submissions[0])))->toBeFalse();
    expect(resultMails())->toHaveCount(1);

    // Revertir y decidir lo mismo otra vez tampoco reenvía el mismo resultado (DA-4).
    app(RevertSubmissionDecision::class)->handle($admin, resultStored($organization, $submissions[0]), confirmedPublished: true);
    app(AcceptSubmission::class)->handle($organizer, resultStored($organization, $submissions[0]));
    expect(resultMails())->toHaveCount(1);

    // Decidir otra cosa sí avisa el nuevo resultado.
    app(RejectSubmission::class)->handle($organizer, $submissions[1]);
    expect(resultMails('submission.rejected'))->toHaveCount(1);
});

it('CA-012-21: a failure publishing the result at decision time keeps the decision', function (): void {
    Queue::fake();
    [$organization, $call, $submissions, , $organizer] = resultsScenario([SubmissionStatus::UnderReview]);
    app(PublishResults::class)->handle($organizer, $call);
    CurrentOrganization::run($organization, fn () => SubmissionParticipant::query()->where('submission_id', $submissions[0]->id)->update(['is_primary' => false]));

    app(AcceptSubmission::class)->handle($organizer, $submissions[0]);

    $stored = resultStored($organization, $submissions[0]);
    expect($stored->status)->toBe(SubmissionStatus::Accepted)->and($stored->results_published_at)->toBeNull()
        ->and(resultMails())->toBeEmpty();
});

it('CA-012-22: an organizer of A cannot publish the results of a call of B', function (): void {
    Queue::fake();
    [, , , , $organizerA] = resultsScenario([SubmissionStatus::Accepted]);
    [$organizationB, $callB, $submissionsB] = resultsScenario([SubmissionStatus::Accepted]);

    expect(fn () => app(PublishResults::class)->handle($organizerA, $callB))->toThrow(AuthorizationException::class);

    expect(resultCall($organizationB, $callB)->results_published_at)->toBeNull()
        ->and(resultStored($organizationB, $submissionsB[0])->results_published_at)->toBeNull()
        ->and(resultMails())->toBeEmpty();
});

it('CA-012-17: a critical result notification cannot be turned off', function (): void {
    [$organization, , , $admin] = resultsScenario([SubmissionStatus::Accepted]);

    foreach (['submission.accepted', 'submission.rejected', 'submission.waitlisted'] as $key) {
        expect(fn () => app(SetNotificationEnabled::class)->handle($admin, $organization, $key, false))
            ->toThrow(CriticalNotificationCannotBeDisabled::class);
    }
});

it('CA-012-14: an invited speaker (not from a call) and a withdrawn proposal are never notified', function (): void {
    Queue::fake();
    [$organization, $call, $submissions, , $organizer] = resultsScenario([SubmissionStatus::Accepted, SubmissionStatus::Accepted]);
    CurrentOrganization::run($organization, fn () => Submission::query()->whereKey($submissions[1]->id)->update(['status' => SubmissionStatus::Withdrawn, 'withdrawn_at' => now()]));

    $report = app(PublishResults::class)->handle($organizer, $call);

    expect($report->published)->toBe(1)->and(resultMails())->toHaveCount(1);
});
