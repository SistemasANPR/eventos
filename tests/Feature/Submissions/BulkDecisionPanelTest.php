<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\SubmissionStatus;
use App\Filament\Resources\Events\Pages\ManageEventSubmissions;
use App\Jobs\SendTransactionalMail;
use App\Models\CallForSubmissions;
use App\Models\DecisionBatch;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 012, F2: las acciones masivas de la lista de propuestas del evento (decidir lo marcado o lo filtrado,
 * revertir un lote, publicar resultados). Delgadas: la regla está en las Actions (BulkDecisionTest y
 * PublishResultsTest); aquí se comprueba que la pantalla las invoca, que respeta los permisos y que nunca
 * omite nada en silencio. Cada prueba usa un solo rol: la caché de permisos de Spatie se comparte en el proceso.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @return Testable<ManageEventSubmissions> */
function bulkPanelList(Organization $organization, User $user, Event $event): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return Livewire::test(ManageEventSubmissions::class, ['record' => $event->id]);
}

/** Propuesta del evento con su autor principal; cada una nace con su propia convocatoria salvo que se comparta. */
function bulkPanelSubmission(Organization $organization, Event $event, SubmissionStatus $status = SubmissionStatus::UnderReview, ?Submission $like = null): Submission
{
    $shared = $like === null ? [] : [
        'call_for_submissions_id' => $like->call_for_submissions_id,
        'submission_type_id' => $like->submission_type_id,
        'form_definition_id' => $like->form_definition_id,
        'track_id' => $like->track_id,
        'privacy_notice_version_id' => $like->privacy_notice_version_id,
    ];

    $submission = Submission::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'status' => $status, ...$shared]);
    SubmissionParticipant::factory()->create(['organization_id' => $organization->id, 'submission_id' => $submission->id]);

    return $submission;
}

function bulkPanelStatus(Organization $organization, Submission $submission): SubmissionStatus
{
    return CurrentOrganization::run($organization, fn (): SubmissionStatus => Submission::query()->findOrFail($submission->id)->status);
}

it('CA-012-9: an organizer decides the marked proposals in bulk from the list and they share a batch', function (): void {
    Queue::fake();
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $first = bulkPanelSubmission($organization, $event);
    $second = bulkPanelSubmission($organization, $event, like: $first);

    bulkPanelList($organization, $organizer, $event)
        ->assertTableBulkActionVisible('reject')
        ->callTableBulkAction('reject', [$first, $second], ['author_comment' => 'Gracias'])
        ->assertHasNoTableBulkActionErrors();

    expect(bulkPanelStatus($organization, $first))->toBe(SubmissionStatus::Rejected)
        ->and(bulkPanelStatus($organization, $second))->toBe(SubmissionStatus::Rejected);
    $batches = CurrentOrganization::run($organization, fn () => DecisionBatch::query()->get());
    expect($batches)->toHaveCount(1)->and($batches[0]->total)->toBe(2)->and($batches[0]->filter['mode'])->toBe('selection');
    Queue::assertNotPushed(SendTransactionalMail::class);
});

it('CA-012-10: ineligible proposals in the marked selection are left alone and reported, never silently', function (): void {
    Queue::fake();
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $eligible = bulkPanelSubmission($organization, $event);
    $accepted = bulkPanelSubmission($organization, $event, SubmissionStatus::Accepted, $eligible);

    bulkPanelList($organization, $organizer, $event)
        ->callTableBulkAction('reject', [$eligible, $accepted])
        ->assertNotified(__('submission_decisions.bulk.done_title'));

    expect(bulkPanelStatus($organization, $eligible))->toBe(SubmissionStatus::Rejected)
        ->and(bulkPanelStatus($organization, $accepted))->toBe(SubmissionStatus::Accepted);
});

it('CA-012-10: a selection with nothing eligible changes nothing and says so', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $accepted = bulkPanelSubmission($organization, $event, SubmissionStatus::Accepted);

    bulkPanelList($organization, $organizer, $event)
        ->callTableBulkAction('reject', [$accepted])
        ->assertNotified(__('submission_decisions.actions.failed'));

    expect(bulkPanelStatus($organization, $accepted))->toBe(SubmissionStatus::Accepted)
        ->and(CurrentOrganization::run($organization, fn (): int => DecisionBatch::query()->count()))->toBe(0);
});

it('CA-012-9: deciding on the filter applies to what the list shows', function (): void {
    Queue::fake();
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $underReview = bulkPanelSubmission($organization, $event);
    $waitlisted = bulkPanelSubmission($organization, $event, SubmissionStatus::Waitlisted, $underReview);
    $submitted = bulkPanelSubmission($organization, $event, SubmissionStatus::Submitted, $underReview);

    bulkPanelList($organization, $organizer, $event)
        ->filterTable('status', 'under_review')
        ->callAction('decideFiltered', ['to' => 'waitlisted'])
        ->assertHasNoActionErrors();

    expect(bulkPanelStatus($organization, $underReview))->toBe(SubmissionStatus::Waitlisted)
        ->and(bulkPanelStatus($organization, $waitlisted))->toBe(SubmissionStatus::Waitlisted)
        ->and(bulkPanelStatus($organization, $submitted))->toBe(SubmissionStatus::Submitted);
    $batch = CurrentOrganization::run($organization, fn () => DecisionBatch::query()->sole());
    expect($batch->filter['mode'])->toBe('filter')->and($batch->filter['status'])->toBe('under_review')->and($batch->total)->toBe(1);
});

it('CA-012-12: an admin reverts a batch from the list; the organizer does not even see the action', function (): void {
    Queue::fake();
    [$organization, $event, $admin] = formScenario();
    $first = bulkPanelSubmission($organization, $event);
    $second = bulkPanelSubmission($organization, $event, like: $first);

    $list = bulkPanelList($organization, $admin, $event)
        ->callTableBulkAction('waitlist', [$first, $second])
        ->assertActionVisible('revertBatch');
    $batch = CurrentOrganization::run($organization, fn () => DecisionBatch::query()->sole());

    $list->callAction('revertBatch', ['batch_id' => $batch->id, 'reason' => 'Por error'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('submission_decisions.bulk.revert_title'));

    expect(bulkPanelStatus($organization, $first))->toBe(SubmissionStatus::UnderReview)
        ->and(bulkPanelStatus($organization, $second))->toBe(SubmissionStatus::UnderReview);
});

it('CA-012-12: an organizer without submissions.revert does not see the batch reversal', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);

    bulkPanelList($organization, $organizer, $event)
        ->assertActionHidden('revertBatch')
        ->assertActionVisible('publishResults')
        ->assertActionVisible('decideFiltered');
});

it('CA-012-19: a user without submissions.decide cannot even open the list that offers the bulk decisions and the publication', function (): void {
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);

    bulkPanelList($organization, $reviewer, $event)->assertForbidden();
});

it('CA-012-14: publishing the results of a call from the list notifies its authors', function (): void {
    Queue::fake();
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $submission = bulkPanelSubmission($organization, $event, SubmissionStatus::Accepted);

    bulkPanelList($organization, $organizer, $event)
        ->callAction('publishResults', ['call_id' => $submission->call_for_submissions_id])
        ->assertHasNoActionErrors()
        ->assertNotified(__('submission_decisions.publish.title'));

    expect(CurrentOrganization::run($organization, fn () => CallForSubmissions::query()->findOrFail($submission->call_for_submissions_id)->results_published_at))->not->toBeNull();
    Queue::assertPushed(SendTransactionalMail::class, fn (SendTransactionalMail $job): bool => $job->notificationKey === 'submission.accepted');
});

it('CA-012-22: a call of another organization cannot be chosen to publish its results', function (): void {
    Queue::fake();
    [$organizationA, $eventA, $organizerA] = formScenario(OrganizationRole::Organizer);
    [$organizationB, $eventB] = formScenario();
    $submissionB = bulkPanelSubmission($organizationB, $eventB, SubmissionStatus::Accepted);

    bulkPanelList($organizationA, $organizerA, $eventA)
        ->callAction('publishResults', ['call_id' => $submissionB->call_for_submissions_id])
        ->assertHasActionErrors(['call_id']);

    expect(CurrentOrganization::run($organizationB, fn () => CallForSubmissions::query()->findOrFail($submissionB->call_for_submissions_id)->results_published_at))->toBeNull();
    Queue::assertNotPushed(SendTransactionalMail::class);
});
