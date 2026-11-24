<?php

declare(strict_types=1);

use App\Actions\Reviews\ComputeSubmissionResults;
use App\Enums\OrganizationRole;
use App\Enums\ReviewAssignmentStatus;
use App\Filament\Resources\Events\Pages\ViewEventReviewResults;
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
use App\Support\Reviews\SubmissionResult;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

/*
 * Plan 011, F6: `ComputeSubmissionResults` (CA-011-22 a 24). Cada prueba usa un solo rol por organización: la
 * caché de permisos de Spatie se comparte dentro del proceso. Las referencias de cálculo están escritas a mano
 * en cada prueba, sin reutilizar el código que se prueba.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/**
 * Evento con una rúbrica publicada del tipo de `base` (mínimo 3 evaluadores, umbral de discrepancia 2) y tres
 * criterios de calificación: A (1 a 5, peso 1), B (1 a 10, peso 2) y C (1 a 5, peso 3), más un comentario.
 *
 * @return array{organization: Organization, event: Event, base: Submission, rubric: Rubric, a: RubricCriterion, b: RubricCriterion, c: RubricCriterion, comment: RubricCriterion, organizer: User}
 */
function rrScenario(OrganizationRole $role = OrganizationRole::Organizer): array
{
    $organization = Memberships::organization();
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $base = Submission::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'title' => 'Base']);
    $rubric = Rubric::factory()->published()->create([
        'organization_id' => $organization->id, 'event_id' => $event->id, 'submission_type_id' => $base->submission_type_id,
        'min_reviewers' => 3, 'discrepancy_threshold' => 2,
    ]);
    $criterion = fn (int $position, string $label, array $attributes = []) => RubricCriterion::factory()->create([
        'organization_id' => $organization->id, 'rubric_id' => $rubric->id, 'position' => $position, 'label' => ['es' => $label], ...$attributes,
    ]);
    $a = $criterion(1, 'Relevancia', ['weight' => 1]);
    $b = $criterion(2, 'Claridad', ['scale_min' => 1, 'scale_max' => 10, 'weight' => 2]);
    $c = $criterion(3, 'Originalidad', ['weight' => 3]);
    $comment = $criterion(4, 'Comentario', ['kind' => 'text']);

    return [
        'organization' => $organization, 'event' => $event, 'base' => $base, 'rubric' => $rubric,
        'a' => $a, 'b' => $b, 'c' => $c, 'comment' => $comment,
        'organizer' => Memberships::attach(User::factory()->create(), $organization, $role),
    ];
}

/**
 * @param  array<string, mixed>  $scenario
 * @param  array<string, mixed>  $overrides
 */
function rrSubmission(array $scenario, string $title, array $overrides = []): Submission
{
    $base = $scenario['base'];

    return Submission::factory()->create([
        'organization_id' => $scenario['organization']->id, 'event_id' => $scenario['event']->id,
        'call_for_submissions_id' => $base->call_for_submissions_id, 'submission_type_id' => $base->submission_type_id,
        'form_definition_id' => $base->form_definition_id, 'track_id' => $base->track_id,
        'privacy_notice_version_id' => $base->privacy_notice_version_id, 'title' => $title, ...$overrides,
    ]);
}

/**
 * Un evaluador con su asignación y, si se piden valores, su evaluación enviada con esas calificaciones.
 *
 * @param  array<string, mixed>  $scenario
 * @param  array<string, int|string>|null  $answers  valor por criterio (clave: a, b, c, comment)
 * @param  array<string, RubricCriterion>|null  $criteria  criterios de la versión con que se evalúa (por clave a, b, c, comment)
 */
function rrReview(array $scenario, Submission $submission, string $name, ?array $answers, ReviewAssignmentStatus $status = ReviewAssignmentStatus::Submitted, int $revision = 1, ?Rubric $rubric = null, ?array $criteria = null): User
{
    $organization = $scenario['organization'];
    $reviewer = Memberships::attach(User::factory()->create(['name' => $name]), $organization, OrganizationRole::Reviewer);
    EventAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $scenario['event']->id, 'user_id' => $reviewer->id]);
    $assignment = ReviewAssignment::factory()->status($status)->create([
        'organization_id' => $organization->id, 'event_id' => $scenario['event']->id, 'submission_id' => $submission->id, 'reviewer_id' => $reviewer->id,
    ]);

    if ($answers !== null) {
        $review = Review::factory()->create([
            'organization_id' => $organization->id, 'review_assignment_id' => $assignment->id,
            'rubric_id' => ($rubric ?? $scenario['rubric'])->id, 'revision' => $revision, 'submitted_at' => $revision >= 1 ? now() : null,
        ]);
        $map = $criteria ?? ['a' => $scenario['a'], 'b' => $scenario['b'], 'c' => $scenario['c'], 'comment' => $scenario['comment']];

        foreach ($answers as $key => $value) {
            ReviewAnswer::factory()->create([
                'organization_id' => $organization->id, 'review_id' => $review->id, 'rubric_criterion_id' => $map[$key]->id,
                'value_int' => is_int($value) ? $value : null, 'value_text' => is_string($value) ? $value : null,
            ]);
        }
    }

    return $reviewer;
}

/**
 * @param  list<SubmissionResult>  $results
 */
function rrFind(array $results, Submission $submission): SubmissionResult
{
    foreach ($results as $result) {
        if ($result->submissionId === $submission->id) {
            return $result;
        }
    }

    throw new RuntimeException('La propuesta no está en los resultados.');
}

it('CA-011-22: the weighted average, the percentage and the deviation match the hand-made reference', function (): void {
    $scenario = rrScenario();
    $submission = rrSubmission($scenario, 'Propuesta uno');
    rrReview($scenario, $submission, 'Ana', ['a' => 4, 'b' => 8, 'c' => 5, 'comment' => 'Muy buena']);
    rrReview($scenario, $submission, 'Beto', ['a' => 2, 'b' => 5, 'c' => 3]);
    rrReview($scenario, $submission, 'Carla', ['a' => 5, 'b' => 10, 'c' => 4]);

    $results = app(ComputeSubmissionResults::class)->handle($scenario['organizer'], $scenario['event']);
    $result = rrFind($results, $submission);

    // Referencia a mano: pesos 1, 2 y 3 (suma 6).
    $ana = (1 * 4 + 2 * 8 + 3 * 5) / 6;
    $beto = (1 * 2 + 2 * 5 + 3 * 3) / 6;
    $carla = (1 * 5 + 2 * 10 + 3 * 4) / 6;
    $mean = ($ana + $beto + $carla) / 3;
    $deviation = sqrt((($ana - $mean) ** 2 + ($beto - $mean) ** 2 + ($carla - $mean) ** 2) / 3);
    // Normalizado por la escala de cada criterio (A y C de 1 a 5, B de 1 a 10).
    $anaPct = (1 * (4 - 1) / 4 + 2 * (8 - 1) / 9 + 3 * (5 - 1) / 4) / 6 * 100;
    $betoPct = (1 * (2 - 1) / 4 + 2 * (5 - 1) / 9 + 3 * (3 - 1) / 4) / 6 * 100;
    $carlaPct = (1 * (5 - 1) / 4 + 2 * (10 - 1) / 9 + 3 * (4 - 1) / 4) / 6 * 100;

    expect($result->evaluations)->toBe(3)
        ->and($result->incomplete)->toBeFalse()
        ->and($result->average)->toEqualWithDelta($mean, 0.0001)
        ->and($result->average)->toEqualWithDelta(5.1666667, 0.0001)
        ->and($result->deviation)->toEqualWithDelta($deviation, 0.0001)
        ->and($result->deviation)->toEqualWithDelta(1.1863, 0.0001)
        ->and($result->percentage)->toEqualWithDelta(($anaPct + $betoPct + $carlaPct) / 3, 0.0001)
        ->and($result->rank)->toBe(1)
        ->and(collect($result->reviews)->pluck('reviewerName')->all())->toBe(['Ana', 'Beto', 'Carla'])
        ->and($result->reviews[0]->average)->toEqualWithDelta($ana, 0.0001)
        ->and($result->reviews[0]->comments)->toBe([['label' => 'Comentario', 'text' => 'Muy buena']]);
});

it('CA-011-22: each review is weighted with the rubric version it was made with', function (): void {
    $scenario = rrScenario();
    $submission = rrSubmission($scenario, 'Con dos versiones');
    // Versión 2: mismos criterios de calificación con otros pesos (1 y 3).
    $second = Rubric::factory()->published()->create([
        'organization_id' => $scenario['organization']->id, 'event_id' => $scenario['event']->id, 'submission_type_id' => $scenario['base']->submission_type_id,
        'version' => 2, 'min_reviewers' => 2, 'discrepancy_threshold' => 5,
    ]);
    $a2 = RubricCriterion::factory()->create(['organization_id' => $scenario['organization']->id, 'rubric_id' => $second->id, 'position' => 1, 'label' => ['es' => 'Relevancia'], 'weight' => 1]);
    $b2 = RubricCriterion::factory()->create(['organization_id' => $scenario['organization']->id, 'rubric_id' => $second->id, 'position' => 2, 'label' => ['es' => 'Claridad'], 'weight' => 3]);

    // Con la versión 1 (pesos 1, 2, 3) y con la 2 (pesos 1 y 3); el mínimo vigente es el de la versión 2.
    rrReview($scenario, $submission, 'Ana', ['a' => 4, 'b' => 2, 'c' => 3]);
    rrReview($scenario, $submission, 'Beto', ['a' => 4, 'b' => 2], rubric: $second, criteria: ['a' => $a2, 'b' => $b2]);

    $result = rrFind(app(ComputeSubmissionResults::class)->handle($scenario['organizer'], $scenario['event']), $submission);

    $ana = (1 * 4 + 2 * 2 + 3 * 3) / 6;
    $beto = (1 * 4 + 3 * 2) / 4;

    expect($result->evaluations)->toBe(2)
        ->and($result->minimum)->toBe(2)
        ->and($result->incomplete)->toBeFalse()
        ->and($result->average)->toEqualWithDelta(($ana + $beto) / 2, 0.0001)
        ->and(collect($result->reviews)->pluck('rubricVersion')->all())->toBe([1, 2]);
});

it('CA-011-22: drafts, cancelled and declined assignments never count', function (): void {
    $scenario = rrScenario();
    $submission = rrSubmission($scenario, 'Con ruido');
    rrReview($scenario, $submission, 'Ana', ['a' => 4, 'b' => 8, 'c' => 5]);
    // Borrador de otra persona (revisión 0, asignación en progreso).
    rrReview($scenario, $submission, 'Borrador', ['a' => 1, 'b' => 1, 'c' => 1], ReviewAssignmentStatus::InProgress, 0);
    // Cancelada con evaluación conservada (DA-7) y conflicto declarado: no cuentan.
    rrReview($scenario, $submission, 'Cancelada', ['a' => 1, 'b' => 1, 'c' => 1], ReviewAssignmentStatus::Cancelled);
    rrReview($scenario, $submission, 'Conflicto', null, ReviewAssignmentStatus::DeclinedConflict);

    $result = rrFind(app(ComputeSubmissionResults::class)->handle($scenario['organizer'], $scenario['event']), $submission);

    expect($result->evaluations)->toBe(1)
        ->and($result->average)->toEqualWithDelta((1 * 4 + 2 * 8 + 3 * 5) / 6, 0.0001)
        ->and($result->reviews)->toHaveCount(1);
});

it('CA-011-23: a submission with fewer reviews than the minimum is incomplete and is not ranked as final', function (): void {
    $scenario = rrScenario();
    $strong = rrSubmission($scenario, 'Fuerte');
    $weak = rrSubmission($scenario, 'Débil');
    $short = rrSubmission($scenario, 'Corta pero perfecta');
    foreach (['Ana', 'Beto', 'Carla'] as $name) {
        rrReview($scenario, $strong, $name, ['a' => 5, 'b' => 9, 'c' => 5]);
        rrReview($scenario, $weak, $name, ['a' => 2, 'b' => 3, 'c' => 2]);
    }
    // Solo dos evaluaciones, con el puntaje más alto de todos.
    rrReview($scenario, $short, 'Dani', ['a' => 5, 'b' => 10, 'c' => 5]);
    rrReview($scenario, $short, 'Eli', ['a' => 5, 'b' => 10, 'c' => 5]);

    $results = app(ComputeSubmissionResults::class)->handle($scenario['organizer'], $scenario['event']);
    $titles = array_map(static fn (SubmissionResult $result): string => $result->title, $results);

    expect(rrFind($results, $short)->incomplete)->toBeTrue()
        ->and(rrFind($results, $short)->rank)->toBeNull()
        ->and(rrFind($results, $short)->evaluations)->toBe(2)
        ->and(rrFind($results, $strong)->rank)->toBe(1)
        ->and(rrFind($results, $weak)->rank)->toBe(2)
        // La incompleta va después de las completas aunque su puntaje sea mayor.
        ->and(array_slice($titles, 0, 2))->toBe(['Fuerte', 'Débil'])
        ->and(array_search('Corta pero perfecta', $titles, true))->toBeGreaterThan(1);
});

it('CA-011-23: tied submissions share the position', function (): void {
    $scenario = rrScenario();
    $first = rrSubmission($scenario, 'Empate A');
    $second = rrSubmission($scenario, 'Empate B');
    $third = rrSubmission($scenario, 'Tercera');
    foreach (['Ana', 'Beto', 'Carla'] as $name) {
        rrReview($scenario, $first, $name, ['a' => 4, 'b' => 8, 'c' => 4]);
        rrReview($scenario, $second, $name, ['a' => 4, 'b' => 8, 'c' => 4]);
        rrReview($scenario, $third, $name, ['a' => 3, 'b' => 6, 'c' => 3]);
    }

    $results = app(ComputeSubmissionResults::class)->handle($scenario['organizer'], $scenario['event']);

    expect(rrFind($results, $first)->rank)->toBe(1)
        ->and(rrFind($results, $second)->rank)->toBe(1)
        ->and(rrFind($results, $third)->rank)->toBe(3);
});

it('CA-011-24: a criterion where two reviewers differ by more than the threshold is listed with every score', function (): void {
    $scenario = rrScenario();
    $submission = rrSubmission($scenario, 'Discrepante');
    rrReview($scenario, $submission, 'Ana', ['a' => 4, 'b' => 8, 'c' => 5]);
    rrReview($scenario, $submission, 'Beto', ['a' => 2, 'b' => 5, 'c' => 3]);
    rrReview($scenario, $submission, 'Carla', ['a' => 5, 'b' => 10, 'c' => 4]);

    $result = rrFind(app(ComputeSubmissionResults::class)->handle($scenario['organizer'], $scenario['event']), $submission);
    $byLabel = collect($result->discrepancies)->keyBy('label');

    // A: 5 - 2 = 3 > 2 y B: 10 - 5 = 5 > 2 son discrepancias; C: 5 - 3 = 2 no supera el umbral (es estricto).
    expect($result->hasDiscrepancies())->toBeTrue()
        ->and($byLabel->keys()->sort()->values()->all())->toBe(['Claridad', 'Relevancia'])
        ->and($byLabel['Relevancia']->spread)->toBe(3)
        ->and($byLabel['Relevancia']->threshold)->toBe(2)
        ->and(collect($byLabel['Relevancia']->scores)->pluck('value', 'reviewer_name')->all())->toBe(['Ana' => 4, 'Beto' => 2, 'Carla' => 5])
        ->and($byLabel['Claridad']->spread)->toBe(5);
});

it('CA-011-24: reviews that agree produce no discrepancy', function (): void {
    $scenario = rrScenario();
    $submission = rrSubmission($scenario, 'Coincidente');
    rrReview($scenario, $submission, 'Ana', ['a' => 4, 'b' => 8, 'c' => 5]);
    rrReview($scenario, $submission, 'Beto', ['a' => 5, 'b' => 9, 'c' => 4]);

    $result = rrFind(app(ComputeSubmissionResults::class)->handle($scenario['organizer'], $scenario['event']), $submission);

    expect($result->hasDiscrepancies())->toBeFalse();
});

it('CA-011-24: without reviews.view_results the results are denied, even for a reviewer', function (OrganizationRole $role): void {
    $scenario = rrScenario($role);
    rrReview($scenario, rrSubmission($scenario, 'Cualquiera'), 'Ana', ['a' => 4, 'b' => 8, 'c' => 5]);

    expect(fn () => app(ComputeSubmissionResults::class)->handle($scenario['organizer'], $scenario['event']))
        ->toThrow(AuthorizationException::class);
})->with([[OrganizationRole::Reviewer]]);

it('CA-011-24: an organization admin and an organizer can see the results', function (OrganizationRole $role): void {
    $scenario = rrScenario($role);
    $submission = rrSubmission($scenario, 'Visible');
    rrReview($scenario, $submission, 'Ana', ['a' => 4, 'b' => 8, 'c' => 5]);

    expect(rrFind(app(ComputeSubmissionResults::class)->handle($scenario['organizer'], $scenario['event']), $submission)->evaluations)->toBe(1);
})->with([[OrganizationRole::OrgAdmin], [OrganizationRole::Organizer]]);

it('CA-011-24: a member of another organization cannot see the results of the event', function (): void {
    $scenario = rrScenario();
    rrReview($scenario, rrSubmission($scenario, 'Ajena'), 'Ana', ['a' => 4, 'b' => 8, 'c' => 5]);
    $other = Memberships::organization();
    $intruder = Memberships::attach(User::factory()->create(), $other, OrganizationRole::OrgAdmin);

    expect(fn () => app(ComputeSubmissionResults::class)->handle($intruder, $scenario['event']))
        ->toThrow(AuthorizationException::class);
});

it('CA-011-22: the results only include submissions of the event and of its organization', function (): void {
    $scenario = rrScenario();
    $mine = rrSubmission($scenario, 'Mía');
    rrReview($scenario, $mine, 'Ana', ['a' => 4, 'b' => 8, 'c' => 5]);

    $other = rrScenario();
    $foreign = rrSubmission($other, 'Ajena');
    rrReview($other, $foreign, 'Zoe', ['a' => 5, 'b' => 10, 'c' => 5]);

    $results = app(ComputeSubmissionResults::class)->handle($scenario['organizer'], $scenario['event']);

    expect(collect($results)->pluck('title')->all())->toBe(['Mía']);
});

it('CA-011-22: filters by track and rejects a track that is not of the event', function (): void {
    $scenario = rrScenario();
    $otherTrack = Track::factory()->create(['organization_id' => $scenario['organization']->id, 'event_id' => $scenario['event']->id]);
    $inBase = rrSubmission($scenario, 'En el eje base');
    $inOther = rrSubmission($scenario, 'En otro eje', ['track_id' => $otherTrack->id]);
    rrReview($scenario, $inBase, 'Ana', ['a' => 4, 'b' => 8, 'c' => 5]);
    rrReview($scenario, $inOther, 'Beto', ['a' => 4, 'b' => 8, 'c' => 5]);

    $filtered = app(ComputeSubmissionResults::class)->handle($scenario['organizer'], $scenario['event'], ['track_id' => $otherTrack->id]);

    expect(collect($filtered)->pluck('title')->all())->toBe(['En otro eje']);

    $foreignTrack = Track::factory()->create();

    expect(fn () => app(ComputeSubmissionResults::class)->handle($scenario['organizer'], $scenario['event'], ['track_id' => $foreignTrack->id]))
        ->toThrow(ValidationException::class);
});

it('CA-011-22: the results are read with a fixed number of queries, not one per submission', function (): void {
    $scenario = rrScenario();
    $count = static function () use ($scenario): int {
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        app(ComputeSubmissionResults::class)->handle($scenario['organizer'], $scenario['event']);

        return $queries;
    };

    $one = rrSubmission($scenario, 'Una');
    rrReview($scenario, $one, 'Ana', ['a' => 4, 'b' => 8, 'c' => 5]);
    $count(); // calienta cachés (roles, permisos) para que solo se mida el cálculo
    $few = $count();

    foreach (range(1, 4) as $number) {
        $submission = rrSubmission($scenario, "Más {$number}");
        rrReview($scenario, $submission, "Evaluador {$number}", ['a' => 4, 'b' => 8, 'c' => 5]);
    }

    expect($count())->toBe($few);
});

it('CA-011-24: the results page shows the ranking, flags incomplete submissions and lists discrepancies with the comments', function (): void {
    $scenario = rrScenario();
    $complete = rrSubmission($scenario, 'Completa discrepante');
    $short = rrSubmission($scenario, 'Solo una evaluación');
    rrReview($scenario, $complete, 'Ana', ['a' => 4, 'b' => 8, 'c' => 5, 'comment' => 'Comentario de Ana']);
    rrReview($scenario, $complete, 'Beto', ['a' => 2, 'b' => 5, 'c' => 3]);
    rrReview($scenario, $complete, 'Carla', ['a' => 5, 'b' => 10, 'c' => 4]);
    rrReview($scenario, $short, 'Dani', ['a' => 5, 'b' => 10, 'c' => 5]);

    Auth::login($scenario['organizer']);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($scenario['organization']);
    CurrentOrganization::set($scenario['organization']);

    Livewire::test(ViewEventReviewResults::class, ['record' => $scenario['event']->id])
        ->assertSuccessful()
        ->assertSee('Completa discrepante')
        ->assertSee('Solo una evaluación')
        ->assertSee(__('review_results.results.incomplete'))
        ->assertSee(__('review_results.results.discrepancy_item', ['label' => 'Relevancia', 'spread' => 3, 'threshold' => 2]))
        ->assertSee('Comentario de Ana')
        ->set('onlyDiscrepancies', true)
        ->assertSee('Completa discrepante')
        ->assertDontSee('Solo una evaluación');
});

it('CA-011-24: the results page denies a reviewer and answers 404 for an event of another organization', function (): void {
    $scenario = rrScenario();
    $reviewer = rrReview($scenario, rrSubmission($scenario, 'Cualquiera'), 'Ana', ['a' => 4, 'b' => 8, 'c' => 5]);
    $slug = $scenario['organization']->slug;
    $url = "/admin/{$slug}/events/{$scenario['event']->id}/review-results";

    $this->actingAs($reviewer)->get($url)->assertForbidden();

    $foreign = rrScenario();

    expect($this->actingAs($scenario['organizer'])->get("/admin/{$foreign['organization']->slug}/events/{$foreign['event']->id}/review-results")->status())->toBeIn([403, 404]);
    $this->actingAs($scenario['organizer'])->get("/admin/{$slug}/events/{$foreign['event']->id}/review-results")->assertNotFound();
});
