<?php

declare(strict_types=1);

use App\Actions\Reviews\ComputeReviewerProgress;
use App\Actions\Reviews\ShowReviewerProgress;
use App\Enums\OrganizationRole;
use App\Enums\ReviewAssignmentStatus;
use App\Filament\Resources\Events\Pages\ManageEventReviewers;
use App\Filament\Resources\Events\Pages\ViewEventReviewerProgress;
use App\Filament\Resources\Events\Pages\ViewEventReviewerProgressDetail;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewAssignment;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Support\Reviews\ReviewerProgress;
use App\Support\Reviews\ReviewerProgressAssignment;
use App\Support\Reviews\ReviewerSummary;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Memberships;
use Tests\Support\TwoFactor;

uses(RefreshDatabase::class);

/*
 * Plan 011, F6: avance por evaluador (CA-011-25 y 55 a 60). Cada prueba usa un solo rol por organización: la
 * caché de permisos de Spatie se comparte dentro del proceso.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @return array{organization: Organization, event: Event, base: Submission, organizer: User} */
function pgScenario(OrganizationRole $role = OrganizationRole::Organizer): array
{
    $organization = Memberships::organization();
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $base = Submission::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);

    return [
        'organization' => $organization, 'event' => $event, 'base' => $base,
        'organizer' => Memberships::attach(User::factory()->create(), $organization, $role),
    ];
}

/** @param  array<string, mixed>  $scenario */
function pgReviewer(array $scenario, string $name): User
{
    $reviewer = Memberships::attach(User::factory()->create(['name' => $name]), $scenario['organization'], OrganizationRole::Reviewer);
    EventAssignment::factory()->create(['organization_id' => $scenario['organization']->id, 'event_id' => $scenario['event']->id, 'user_id' => $reviewer->id]);

    return $reviewer;
}

/**
 * Una asignación del evaluador sobre una propuesta nueva del evento (o sobre la dada).
 *
 * @param  array<string, mixed>  $scenario
 * @param  array<string, mixed>  $submissionOverrides
 */
function pgAssign(array $scenario, User $reviewer, ReviewAssignmentStatus $status, array $submissionOverrides = [], ?Submission $submission = null): ReviewAssignment
{
    $base = $scenario['base'];
    $submission ??= Submission::factory()->create([
        'organization_id' => $scenario['organization']->id, 'event_id' => $scenario['event']->id,
        'call_for_submissions_id' => $base->call_for_submissions_id, 'submission_type_id' => $base->submission_type_id,
        'form_definition_id' => $base->form_definition_id, 'track_id' => $base->track_id,
        'privacy_notice_version_id' => $base->privacy_notice_version_id, ...$submissionOverrides,
    ]);

    return ReviewAssignment::factory()->status($status)->create([
        'organization_id' => $scenario['organization']->id, 'event_id' => $scenario['event']->id,
        'submission_id' => $submission->id, 'reviewer_id' => $reviewer->id,
    ]);
}

/**
 * @param  array<string, mixed>  $scenario
 * @param  array<string, int>  $counts  cuántas asignaciones por estado (clave: valor del estado)
 */
function pgAssignMany(array $scenario, User $reviewer, array $counts): void
{
    foreach ($counts as $status => $total) {
        foreach (range(1, $total) as $ignored) {
            pgAssign($scenario, $reviewer, ReviewAssignmentStatus::from((string) $status));
        }
    }
}

/**
 * @param  array<string, mixed>  $scenario
 * @param  array<string, mixed>  $filters
 * @return array<string, ReviewerProgress>
 */
function pgProgressByName(array $scenario, array $filters = [], string $direction = 'asc'): array
{
    $report = app(ComputeReviewerProgress::class)->handle($scenario['organizer'], $scenario['event'], $filters, $direction);

    return collect($report->rows)->mapWithKeys(fn ($row): array => [$row->name => $row->progress])->all();
}

it('CA-011-55: eight of ten submitted is 80 percent with the breakdown by state', function (): void {
    $scenario = pgScenario();
    $reviewer = pgReviewer($scenario, 'Evaluador Ejemplo');
    pgAssignMany($scenario, $reviewer, ['submitted' => 8, 'in_progress' => 1, 'pending' => 1]);

    $report = app(ComputeReviewerProgress::class)->handle($scenario['organizer'], $scenario['event']);
    $progress = $report->rows[0]->progress;

    expect($report->rows)->toHaveCount(1)
        ->and($report->rows[0]->name)->toBe('Evaluador Ejemplo')
        ->and($progress->percentage())->toBe(80)
        ->and([$progress->submitted, $progress->inProgress, $progress->pending, $progress->declinedConflict])->toBe([8, 1, 1, 0])
        ->and($progress->label())->toBe('80 % · 8 de 10');
});

it('CA-011-25: each reviewer shows the percentage and the count, and the page prints them', function (): void {
    $scenario = pgScenario();
    pgAssignMany($scenario, pgReviewer($scenario, 'Ana'), ['submitted' => 1, 'pending' => 3]);
    pgAssignMany($scenario, pgReviewer($scenario, 'Beto'), ['submitted' => 4, 'pending' => 1]);

    Auth::login($scenario['organizer']);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($scenario['organization']);
    CurrentOrganization::set($scenario['organization']);

    Livewire::test(ViewEventReviewerProgress::class, ['record' => $scenario['event']->id])
        ->assertSuccessful()
        ->assertSee('Ana')
        ->assertSee('25 % · 1 de 4')
        ->assertSee('Beto')
        ->assertSee('80 % · 4 de 5');
});

it('CA-011-56: cancelled assignments are out of the numerator and the denominator and conflicts are counted apart', function (): void {
    $scenario = pgScenario();
    $mixed = pgReviewer($scenario, 'Mixto');
    pgAssignMany($scenario, $mixed, ['submitted' => 8, 'pending' => 1, 'cancelled' => 5, 'declined_conflict' => 2]);
    $onlyConflict = pgReviewer($scenario, 'Solo conflicto');
    pgAssignMany($scenario, $onlyConflict, ['declined_conflict' => 2, 'cancelled' => 1]);
    pgReviewer($scenario, 'Sin nada');

    $progress = pgProgressByName($scenario);

    // 8 enviadas de 9 activas: 88.9 % redondeado a 89; las 5 canceladas no cuentan y las 2 de conflicto, aparte.
    expect($progress['Mixto']->percentage())->toBe(89)
        ->and($progress['Mixto']->active())->toBe(9)
        ->and($progress['Mixto']->declinedConflict)->toBe(2)
        ->and($progress['Mixto']->label())->toBe('89 % · 8 de 9')
        // Sin asignaciones activas: «sin asignaciones», no 0 %.
        ->and($progress['Solo conflicto']->percentage())->toBeNull()
        ->and($progress['Solo conflicto']->label())->toBe(__('review_assignments.reviewers.no_assignments'))
        ->and($progress['Solo conflicto']->declinedConflict)->toBe(2)
        ->and($progress['Sin nada']->percentage())->toBeNull()
        ->and($progress['Sin nada']->hasAssignments())->toBeFalse();
});

it('CA-011-56: the by-reviewer view of the assignment screen uses the same calculation', function (): void {
    $scenario = pgScenario();
    pgAssignMany($scenario, pgReviewer($scenario, 'Ana'), ['submitted' => 2, 'in_progress' => 1, 'cancelled' => 1]);

    Auth::login($scenario['organizer']);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($scenario['organization']);
    CurrentOrganization::set($scenario['organization']);

    Livewire::test(ManageEventReviewers::class, ['record' => $scenario['event']->id])
        ->assertSee('67 % · 2 de 3');
});

it('CA-011-57: orders by percentage, with reviewers without assignments last, and filters by reviewer, track and call', function (): void {
    $scenario = pgScenario();
    $low = pgReviewer($scenario, 'Bajo');
    $high = pgReviewer($scenario, 'Alto');
    $none = pgReviewer($scenario, 'Ninguno');
    $otherTrack = Track::factory()->create(['organization_id' => $scenario['organization']->id, 'event_id' => $scenario['event']->id]);
    pgAssignMany($scenario, $low, ['submitted' => 1, 'pending' => 3]);
    pgAssignMany($scenario, $high, ['submitted' => 3, 'pending' => 1]);
    pgAssign($scenario, $low, ReviewAssignmentStatus::Submitted, ['track_id' => $otherTrack->id]);

    $names = fn (string $direction, array $filters = []): array => array_map(
        fn ($row): string => $row->name,
        app(ComputeReviewerProgress::class)->handle($scenario['organizer'], $scenario['event'], $filters, $direction)->rows,
    );

    expect($names('asc'))->toBe(['Bajo', 'Alto', 'Ninguno'])
        ->and($names('desc'))->toBe(['Alto', 'Bajo', 'Ninguno'])
        ->and($names('asc', ['reviewer_id' => $high->id]))->toBe(['Alto']);

    // Con el eje: solo cuenta la propuesta del otro eje, que es de Bajo; el avance global la respeta.
    $filtered = app(ComputeReviewerProgress::class)->handle($scenario['organizer'], $scenario['event'], ['track_id' => $otherTrack->id]);

    expect($filtered->overall->label())->toBe('100 % · 1 de 1')
        ->and(collect($filtered->rows)->firstWhere('name', 'Bajo')->progress->label())->toBe('100 % · 1 de 1');

    $overall = app(ComputeReviewerProgress::class)->handle($scenario['organizer'], $scenario['event'])->overall;

    // 5 enviadas de 9 activas en total.
    expect($overall->submitted)->toBe(5)->and($overall->active())->toBe(9)->and($overall->percentage())->toBe(56);
});

it('CA-011-57: a reviewer below the threshold is flagged and one without assignments is not', function (): void {
    $progress = new ReviewerProgress(pending: 3, inProgress: 0, submitted: 1);

    expect($progress->isBelow(50))->toBeTrue()
        ->and($progress->isBelow(25))->toBeFalse()
        ->and((new ReviewerProgress)->isBelow(50))->toBeFalse();
});

it('CA-011-57: the page highlights reviewers below the threshold and shows the overall progress', function (): void {
    $scenario = pgScenario();
    pgAssignMany($scenario, pgReviewer($scenario, 'Atrasada'), ['submitted' => 1, 'pending' => 4]);
    pgAssignMany($scenario, pgReviewer($scenario, 'Al día'), ['submitted' => 4, 'pending' => 1]);

    Auth::login($scenario['organizer']);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($scenario['organization']);
    CurrentOrganization::set($scenario['organization']);

    $html = Livewire::test(ViewEventReviewerProgress::class, ['record' => $scenario['event']->id])
        ->assertSee('50 % · 5 de 10')
        ->assertSee(__('review_results.progress.below_threshold'))
        ->html();

    expect(substr_count($html, 'data-low="true"'))->toBe(1);
});

it('CA-011-57: an invalid filter is rejected with a validation error', function (): void {
    $scenario = pgScenario();
    $foreignTrack = Track::factory()->create();

    expect(fn () => app(ComputeReviewerProgress::class)->handle($scenario['organizer'], $scenario['event'], ['track_id' => $foreignTrack->id]))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(ComputeReviewerProgress::class)->handle($scenario['organizer'], $scenario['event'], [], 'sideways'))
        ->toThrow(ValidationException::class);
});

it('CA-011-58: the detail lists each assignment with its state and last activity and carries no scores or comments', function (): void {
    $scenario = pgScenario();
    $reviewer = pgReviewer($scenario, 'Ana');
    $rubric = Rubric::factory()->published()->create([
        'organization_id' => $scenario['organization']->id, 'event_id' => $scenario['event']->id, 'submission_type_id' => $scenario['base']->submission_type_id,
    ]);
    $criterion = RubricCriterion::factory()->create(['organization_id' => $scenario['organization']->id, 'rubric_id' => $rubric->id]);
    $sent = pgAssign($scenario, $reviewer, ReviewAssignmentStatus::Submitted, ['title' => 'Enviada']);
    $pending = pgAssign($scenario, $reviewer, ReviewAssignmentStatus::Pending, ['title' => 'Pendiente']);
    $review = Review::factory()->submitted()->create(['organization_id' => $scenario['organization']->id, 'review_assignment_id' => $sent->id, 'rubric_id' => $rubric->id]);
    ReviewAnswer::factory()->create(['organization_id' => $scenario['organization']->id, 'review_id' => $review->id, 'rubric_criterion_id' => $criterion->id, 'value_int' => 5]);
    CurrentOrganization::run($scenario['organization'], fn () => Review::query()->whereKey($review->id)->update(['updated_at' => '2027-01-05 10:00:00']));

    $detail = app(ShowReviewerProgress::class)->handle($scenario['organizer'], $scenario['event'], $reviewer->id);
    $items = collect($detail->assignments->items())->keyBy('title');

    expect($detail->name)->toBe('Ana')
        ->and($detail->progress->label())->toBe('50 % · 1 de 2')
        ->and($items['Enviada']->status)->toBe(ReviewAssignmentStatus::Submitted)
        ->and($items['Enviada']->lastActivityAt?->toDateTimeString())->toBe('2027-01-05 10:00:00')
        ->and($items['Pendiente']->status)->toBe(ReviewAssignmentStatus::Pending)
        ->and($items['Pendiente']->lastActivityAt)->toBeNull()
        // Por diseño, la fila no tiene dónde llevar calificaciones ni comentarios.
        ->and(array_keys(get_object_vars($items['Enviada'])))->toBe(['submissionId', 'title', 'status', 'lastActivityAt'])
        ->and($pending->id)->not->toBeNull();

    Auth::login($scenario['organizer']);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($scenario['organization']);
    CurrentOrganization::set($scenario['organization']);

    Livewire::test(ViewEventReviewerProgressDetail::class, ['record' => $scenario['event']->id, 'reviewer' => $reviewer->id])
        ->assertSuccessful()
        ->assertSee('Enviada')
        ->assertSee('Pendiente')
        ->assertDontSee('Muy buena');
});

it('CA-011-58: the detail is paginated with a cap of 100 rows', function (): void {
    $scenario = pgScenario();
    $reviewer = pgReviewer($scenario, 'Ana');
    pgAssignMany($scenario, $reviewer, ['pending' => 3]);

    $detail = app(ShowReviewerProgress::class)->handle($scenario['organizer'], $scenario['event'], $reviewer->id, 5000);

    expect($detail->assignments->perPage())->toBe(100)
        ->and($detail->assignments->total())->toBe(3)
        ->and($detail->assignments->items()[0])->toBeInstanceOf(ReviewerProgressAssignment::class);
});

it('CA-011-59: without reviews.view_progress the progress and its detail are denied in the server', function (): void {
    $scenario = pgScenario(OrganizationRole::Reviewer);
    $reviewer = pgReviewer($scenario, 'Ana');
    $slug = $scenario['organization']->slug;
    $base = "/admin/{$slug}/events/{$scenario['event']->id}";

    expect(fn () => app(ComputeReviewerProgress::class)->handle($scenario['organizer'], $scenario['event']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ShowReviewerProgress::class)->handle($scenario['organizer'], $scenario['event'], $reviewer->id))->toThrow(AuthorizationException::class);

    // Por la ruta: un evaluador no entra a la página ni al detalle.
    $this->actingAs($reviewer)->get("{$base}/review-progress")->assertForbidden();
    $this->actingAs($reviewer)->get("{$base}/review-progress/{$reviewer->id}")->assertForbidden();
});

it('CA-011-59: a reviewer of another event or organization answers 404 and a non-uuid too', function (): void {
    $scenario = pgScenario();
    $otherEvent = Event::factory()->create(['organization_id' => $scenario['organization']->id]);
    $inOtherEvent = Memberships::attach(User::factory()->create(), $scenario['organization'], OrganizationRole::Reviewer);
    EventAssignment::factory()->create(['organization_id' => $scenario['organization']->id, 'event_id' => $otherEvent->id, 'user_id' => $inOtherEvent->id]);

    $foreignScenario = pgScenario();
    $foreign = pgReviewer($foreignScenario, 'Ajeno');

    $show = fn (string $id) => fn () => app(ShowReviewerProgress::class)->handle($scenario['organizer'], $scenario['event'], $id);

    expect($show($inOtherEvent->id))->toThrow(ModelNotFoundException::class)
        ->and($show($foreign->id))->toThrow(ModelNotFoundException::class)
        ->and($show('no-es-uuid'))->toThrow(ModelNotFoundException::class);

    $slug = $scenario['organization']->slug;
    $this->actingAs($scenario['organizer'])->get("/admin/{$slug}/events/{$scenario['event']->id}/review-progress/{$foreign->id}")->assertNotFound();
});

it('CA-011-59: the progress of an event of another organization is denied', function (): void {
    $scenario = pgScenario();
    $foreignScenario = pgScenario();

    expect(fn () => app(ComputeReviewerProgress::class)->handle($scenario['organizer'], $foreignScenario['event']))
        ->toThrow(AuthorizationException::class);

    $slug = $foreignScenario['organization']->slug;
    expect($this->actingAs($scenario['organizer'])->get("/admin/{$slug}/events/{$foreignScenario['event']->id}/review-progress")->status())->toBeIn([403, 404]);
});

it('CA-011-60: the dashboard of a reviewer shows only their own progress', function (): void {
    $scenario = pgScenario();
    $mine = pgReviewer($scenario, 'Yo');
    $other = pgReviewer($scenario, 'Otra persona');
    TwoFactor::enable($mine);
    pgAssignMany($scenario, $mine, ['submitted' => 1, 'pending' => 3]);
    pgAssignMany($scenario, $other, ['submitted' => 7, 'pending' => 1]);

    CurrentOrganization::run($scenario['organization'], function () use ($mine): void {
        expect(ReviewerSummary::progress($mine)->label())->toBe('25 % · 1 de 4');
    });

    $html = $this->actingAs($mine->fresh())->get("/reviewer/{$scenario['organization']->slug}")->assertOk()->getContent();

    expect($html)->toContain('25 % · 1 de 4')
        ->and($html)->not->toContain('88 % · 7 de 8')
        ->and($html)->not->toContain('Otra persona');
});
