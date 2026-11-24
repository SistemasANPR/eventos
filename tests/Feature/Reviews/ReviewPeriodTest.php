<?php

declare(strict_types=1);

use App\Actions\Reviews\CloseReviewPeriod;
use App\Actions\Reviews\ReopenReviewPeriod;
use App\Actions\Reviews\SubmitReview;
use App\Enums\OrganizationRole;
use App\Exceptions\Domain\ReviewPeriodClosed;
use App\Filament\Resources\Events\Pages\ViewEventReviewerProgress;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\Submission;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

/*
 * Plan 011, F6: cierre y reapertura del periodo de evaluación (CA-011-26 y 27). Cada prueba usa un solo rol por
 * organización: la caché de permisos de Spatie se comparte dentro del proceso.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/**
 * Evento con rúbrica publicada de un criterio, un evaluador asignado y un usuario con el rol pedido.
 *
 * @return array{organization: Organization, event: Event, actor: User, reviewer: User, assignment: ReviewAssignment, criterion: RubricCriterion}
 */
function cpScenario(OrganizationRole $role = OrganizationRole::Organizer): array
{
    $organization = Memberships::organization();
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $submission = Submission::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);
    $rubric = Rubric::factory()->published()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'submission_type_id' => $submission->submission_type_id]);
    $criterion = RubricCriterion::factory()->create(['organization_id' => $organization->id, 'rubric_id' => $rubric->id]);
    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    EventAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'user_id' => $reviewer->id]);
    $assignment = ReviewAssignment::factory()->create([
        'organization_id' => $organization->id, 'event_id' => $event->id, 'submission_id' => $submission->id, 'reviewer_id' => $reviewer->id,
    ]);

    return [
        'organization' => $organization, 'event' => $event, 'reviewer' => $reviewer, 'assignment' => $assignment, 'criterion' => $criterion,
        'actor' => Memberships::attach(User::factory()->create(), $organization, $role),
    ];
}

it('CA-011-26: closing the period stops reviewers from creating or changing reviews and records who and when', function (): void {
    $scenario = cpScenario();
    $answers = [$scenario['criterion']->id => 4];

    $closed = app(CloseReviewPeriod::class)->handle($scenario['actor'], $scenario['event'], ['reason' => 'Fin de la evaluación']);

    expect($closed->review_period_closed_at)->not->toBeNull()
        ->and($closed->reviewPeriodIsOpen())->toBeFalse()
        // De punta a punta: ni un envío ni un borrador pasan, con un error visible.
        ->and(fn () => app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], $answers))->toThrow(ReviewPeriodClosed::class)
        ->and(fn () => app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], $answers, asDraft: true))->toThrow(ReviewPeriodClosed::class);

    $log = Activity::query()->where('event', 'review_period_closed')->sole();

    expect($log->causer_id)->toBe($scenario['actor']->id)
        ->and($log->subject_id)->toBe($scenario['event']->id)
        ->and($log->properties->get('event_id'))->toBe($scenario['event']->id)
        ->and($log->properties->get('reason'))->toBe('Fin de la evaluación')
        ->and($log->properties->get('closed_at'))->not->toBeNull()
        ->and($log->created_at)->not->toBeNull();

    CurrentOrganization::run($scenario['organization'], function () use ($scenario): void {
        expect(ReviewAssignment::query()->findOrFail($scenario['assignment']->id)->status->value)->toBe('pending');
    });
});

it('CA-011-26: closing an already closed period keeps the original instant and does not audit again', function (): void {
    $scenario = cpScenario();

    $first = app(CloseReviewPeriod::class)->handle($scenario['actor'], $scenario['event']);
    $closedAt = $first->review_period_closed_at?->toIso8601String();
    $again = app(CloseReviewPeriod::class)->handle($scenario['actor'], $scenario['event']);

    expect($again->review_period_closed_at?->toIso8601String())->toBe($closedAt)
        ->and(Activity::query()->where('event', 'review_period_closed')->count())->toBe(1);
});

it('CA-011-27: a user with reviews.close_period reopens the period, it is audited and reviewers can grade again', function (): void {
    $scenario = cpScenario();
    app(CloseReviewPeriod::class)->handle($scenario['actor'], $scenario['event']);

    $reopened = app(ReopenReviewPeriod::class)->handle($scenario['actor'], $scenario['event'], ['reason' => 'Se amplió el plazo']);

    expect($reopened->review_period_closed_at)->toBeNull()
        ->and($reopened->reviewPeriodIsOpen())->toBeTrue();

    $log = Activity::query()->where('event', 'review_period_reopened')->sole();

    expect($log->causer_id)->toBe($scenario['actor']->id)
        ->and($log->properties->get('reason'))->toBe('Se amplió el plazo')
        ->and($log->properties->get('previously_closed_at'))->not->toBeNull();

    $review = app(SubmitReview::class)->handle($scenario['reviewer'], $scenario['assignment'], [$scenario['criterion']->id => 4]);

    expect($review->revision)->toBe(1);
});

it('CA-011-27: reopening an open period changes nothing and records nothing', function (): void {
    $scenario = cpScenario();

    app(ReopenReviewPeriod::class)->handle($scenario['actor'], $scenario['event']);

    expect(Activity::query()->where('event', 'review_period_reopened')->count())->toBe(0);
});

it('CA-011-27: without reviews.close_period closing and reopening are denied, even for a reviewer', function (): void {
    $scenario = cpScenario(OrganizationRole::Reviewer);

    expect(fn () => app(CloseReviewPeriod::class)->handle($scenario['actor'], $scenario['event']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReopenReviewPeriod::class)->handle($scenario['actor'], $scenario['event']))->toThrow(AuthorizationException::class)
        ->and(Event::query()->withoutGlobalScopes()->findOrFail($scenario['event']->id)->review_period_closed_at)->toBeNull();
});

it('CA-011-27: a member of another organization cannot close the period of the event', function (): void {
    $scenario = cpScenario();
    $other = Memberships::organization();
    $intruder = Memberships::attach(User::factory()->create(), $other, OrganizationRole::OrgAdmin);

    expect(fn () => app(CloseReviewPeriod::class)->handle($intruder, $scenario['event']))->toThrow(AuthorizationException::class)
        ->and(Event::query()->withoutGlobalScopes()->findOrFail($scenario['event']->id)->review_period_closed_at)->toBeNull();
});

it('CA-011-26: an invalid reason is rejected', function (): void {
    $scenario = cpScenario();

    expect(fn () => app(CloseReviewPeriod::class)->handle($scenario['actor'], $scenario['event'], ['reason' => str_repeat('x', 501)]))
        ->toThrow(ValidationException::class);
});

it('CA-011-26: the progress page closes and reopens the period with its actions', function (): void {
    $scenario = cpScenario();

    Auth::login($scenario['actor']);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($scenario['organization']);
    CurrentOrganization::set($scenario['organization']);

    $page = Livewire::test(ViewEventReviewerProgress::class, ['record' => $scenario['event']->id])
        ->assertActionVisible('closePeriod')
        ->assertActionHidden('reopenPeriod')
        ->callAction('closePeriod', ['reason' => 'Cierre'])
        ->assertNotified(__('review_results.period.closed'))
        ->assertActionHidden('closePeriod')
        ->assertActionVisible('reopenPeriod')
        ->assertSee(__('review_results.progress.period_closed'));

    expect(Event::query()->findOrFail($scenario['event']->id)->reviewPeriodIsOpen())->toBeFalse();

    $page->callAction('reopenPeriod')->assertNotified(__('review_results.period.reopened'));

    expect(Event::query()->findOrFail($scenario['event']->id)->reviewPeriodIsOpen())->toBeTrue();
});
