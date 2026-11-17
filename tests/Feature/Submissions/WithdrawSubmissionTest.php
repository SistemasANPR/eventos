<?php

declare(strict_types=1);

use App\Actions\Submissions\WithdrawSubmission;
use App\Enums\OrganizationRole;
use App\Enums\SubmissionStatus;
use App\Exceptions\Domain\SubmissionNotWithdrawable;
use App\Filament\Resources\Submissions\SubmissionResource;
use App\Jobs\SendTransactionalMail;
use App\Models\Activity;
use App\Models\Submission;
use App\Models\User;
use App\Support\Auth\AuthorSession;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Support\AuthorFixtures;
use Tests\Support\Memberships;

/*
 * El autor retira su propuesta (plan 006, H-006-5, CA-006-46 a 49).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo('2027-01-20 12:00:00');
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return list<SendTransactionalMail> */
function withdrawnMails(): array
{
    return Queue::pushed(SendTransactionalMail::class, fn (SendTransactionalMail $job): bool => $job->notificationKey === 'submission.withdrawn')->values()->all();
}

it('CA-006-46: withdrawing a submitted proposal marks it withdrawn, audits it and queues the mail to the author and the team', function (): void {
    Queue::fake();
    $organization = Memberships::organization();
    $submission = AuthorFixtures::submission($organization);
    $organizer = Memberships::attach(User::factory()->create(['email' => 'org@example.test']), $organization, OrganizationRole::Organizer);
    app(AuthorSession::class)->open($submission);

    $this->post(AuthorFixtures::path($submission, 'my-submission/withdraw'))
        ->assertRedirect(AuthorFixtures::path($submission));

    $fresh = Submission::query()->findOrFail($submission->id);
    $recipients = array_map(static fn (SendTransactionalMail $job): string => $job->recipient->email, withdrawnMails());
    sort($recipients);
    $authorMail = collect(withdrawnMails())->first(fn (SendTransactionalMail $job): bool => $job->recipient->email === 'ana@example.test');
    expect($fresh->status)->toBe(SubmissionStatus::Withdrawn)
        ->and($fresh->withdrawn_at?->equalTo(now()))->toBeTrue()
        ->and($fresh->version)->toBe(2)
        ->and($recipients)->toBe(['ana@example.test', $organizer->email])
        ->and($authorMail->variables['panel_url'])->toContain('/my-submission/request-link')
        ->and($authorMail->variables['panel_url'])->not->toContain('/access/')
        ->and(Activity::query()->where('event', 'submission_withdrawn')->count())->toBe(1);
});

it('CA-006-47: withdrawing an already withdrawn proposal changes nothing and sends no second mail', function (): void {
    Queue::fake();
    $submission = AuthorFixtures::submission();
    app(AuthorSession::class)->open($submission);
    $path = AuthorFixtures::path($submission, 'my-submission/withdraw');

    $this->post($path)->assertRedirect();
    $withdrawnAt = Submission::query()->findOrFail($submission->id)->withdrawn_at;
    $this->travel(5)->minutes();
    $this->post($path)->assertRedirect();

    $fresh = Submission::query()->findOrFail($submission->id);
    expect($fresh->withdrawn_at?->equalTo($withdrawnAt))->toBeTrue()
        ->and($fresh->version)->toBe(2)
        ->and(withdrawnMails())->toHaveCount(1)
        ->and(Activity::query()->where('event', 'submission_withdrawn')->count())->toBe(1);
});

it('CA-006-47: the action reports whether it changed anything', function (): void {
    Queue::fake();
    $submission = AuthorFixtures::submission();

    $first = app(WithdrawSubmission::class)->handle($submission);
    $second = app(WithdrawSubmission::class)->handle($submission);

    expect($first)->toBeTrue()->and($second)->toBeFalse();
});

it('CA-006-46: a proposal the team already moved past submitted cannot be withdrawn by the author', function (): void {
    Queue::fake();
    $submission = AuthorFixtures::submission(submission: ['status' => SubmissionStatus::Accepted]);

    expect(fn () => app(WithdrawSubmission::class)->handle($submission))->toThrow(SubmissionNotWithdrawable::class)
        ->and(Submission::query()->findOrFail($submission->id)->status)->toBe(SubmissionStatus::Accepted);
    Queue::assertNothingPushed();
});

it('CA-006-46: withdrawing does not depend on the call being open (DA-7)', function (): void {
    Queue::fake();
    // El factory de la convocatoria cierra el 2027-02-15: un mes después ya está cerrada.
    $submission = AuthorFixtures::submission();
    $this->travelTo('2027-06-01 12:00:00');
    app(AuthorSession::class)->open($submission);

    $this->post(AuthorFixtures::path($submission, 'my-submission/withdraw'))->assertRedirect();

    expect(Submission::query()->findOrFail($submission->id)->status)->toBe(SubmissionStatus::Withdrawn);
});

it('CA-006-48: a GET to the withdraw action answers 405, and without a session the visitor must ask for a link', function (): void {
    $submission = AuthorFixtures::submission();
    $path = AuthorFixtures::path($submission, 'my-submission/withdraw');

    $this->post($path)->assertRedirect(AuthorFixtures::path($submission, 'my-submission/request-link'));
    expect(Submission::query()->findOrFail($submission->id)->status)->toBe(SubmissionStatus::Submitted);

    app(AuthorSession::class)->open($submission);
    $this->get($path)->assertStatus(405);
    expect(Submission::query()->findOrFail($submission->id)->status)->toBe(SubmissionStatus::Submitted);
});

it('CA-006-48: the withdraw route is a web route, so it carries the session and CSRF protection', function (): void {
    $route = Route::getRoutes()->getByName('author.withdraw');

    expect($route)->not->toBeNull()
        ->and($route?->methods())->toBe(['POST'])
        ->and($route?->gatherMiddleware())->toContain('web');
});

it('CA-006-38: an author session cannot withdraw a proposal of another organization', function (): void {
    $mine = AuthorFixtures::submission();
    $other = AuthorFixtures::submission();
    app(AuthorSession::class)->open($mine);

    $this->post(AuthorFixtures::path($other, 'my-submission/withdraw'))->assertNotFound();

    expect(Submission::query()->withoutGlobalScopes()->findOrFail($other->id)->status)->toBe(SubmissionStatus::Submitted);
});

it('CA-006-49: a withdrawn proposal stays visible to the team with its status and is never physically deleted', function (): void {
    Queue::fake();
    $submission = AuthorFixtures::submission();
    app(WithdrawSubmission::class)->handle($submission);

    $visible = SubmissionResource::getEloquentQuery()->whereKey($submission->id)->first();

    expect($visible)->toBeInstanceOf(Submission::class)
        ->and($visible instanceof Submission ? $visible->status : null)->toBe(SubmissionStatus::Withdrawn)
        ->and($visible instanceof Submission && $visible->trashed())->toBeFalse()
        ->and(Submission::query()->withTrashed()->whereKey($submission->id)->exists())->toBeTrue();
});

it('CA-006-46: the proposal page offers withdrawing only while the proposal is submitted', function (): void {
    Queue::fake();
    $submission = AuthorFixtures::submission();
    app(AuthorSession::class)->open($submission);
    $path = AuthorFixtures::path($submission);

    $this->get($path)->assertInertia(fn ($page) => $page->where('withdrawUrl', AuthorFixtures::path($submission, 'my-submission/withdraw')));

    app(WithdrawSubmission::class)->handle($submission);

    $this->get($path)->assertInertia(fn ($page) => $page->where('withdrawUrl', null)->where('submission.status', 'withdrawn'));
});
