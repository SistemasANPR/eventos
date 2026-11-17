<?php

declare(strict_types=1);

use App\Actions\Reviews\PublishRubric;
use App\Actions\Reviews\SaveRubric;
use App\Enums\CriterionKind;
use App\Enums\OrganizationRole;
use App\Enums\RubricStatus;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\SubmissionType;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;
use Tests\Support\RubricUsage;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed> datos válidos de una rúbrica con dos criterios de calificación y uno de texto
 */
function rubricData(SubmissionType $type, array $overrides = []): array
{
    return array_replace([
        'submission_type_id' => $type->id,
        'blind_review' => false,
        'min_reviewers' => 2,
        'discrepancy_threshold' => 2,
        'criteria' => [
            ['label' => ['es' => 'Relevancia', 'en' => 'Relevance'], 'kind' => 'scale', 'scale_min' => 1, 'scale_max' => 5, 'weight' => 2],
            ['label' => ['es' => 'Claridad'], 'kind' => 'scale', 'scale_min' => 1, 'scale_max' => 10, 'weight' => 1],
            ['label' => ['es' => 'Comentarios'], 'kind' => 'text', 'weight' => 1],
        ],
    ], $overrides);
}

/** @return array{0: Organization, 1: Event, 2: SubmissionType, 3: User, 4: User} organización con roles, evento, tipo, administrador y organizador */
function rubricScenario(): array
{
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $type = SubmissionType::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);

    return [$organization, $event, $type, $admin, $organizer];
}

it('CA-011-1: an organizer creates a draft rubric with ordered, translatable criteria', function (): void {
    [$organization, $event, $type, , $organizer] = rubricScenario();

    $rubric = app(SaveRubric::class)->handle($organizer, $event, rubricData($type, ['blind_review' => true]));

    expect($rubric->status)->toBe(RubricStatus::Draft)
        ->and($rubric->version)->toBe(1)
        ->and($rubric->blind_review)->toBeTrue()
        ->and($rubric->organization_id)->toBe($organization->id)
        ->and($rubric->submission_type_id)->toBe($type->id)
        ->and($rubric->criteria->pluck('position')->all())->toBe([1, 2, 3])
        ->and($rubric->criteria->map(fn (RubricCriterion $c): string => $c->labelFor('es'))->all())->toBe(['Relevancia', 'Claridad', 'Comentarios'])
        ->and($rubric->criteria->first()->labelFor('en'))->toBe('Relevance')
        ->and($rubric->criteria->first()->getTranslations('label'))->toEqual(['es' => 'Relevancia', 'en' => 'Relevance'])
        ->and($rubric->criteria[2]->kind)->toBe(CriterionKind::Text)
        ->and($rubric->criteria[0]->weight)->toBe('2.00')
        ->and(Activity::query()->where('event', 'rubric_created')->count())->toBe(1);
});

it('CA-011-1: an org admin can also create one, and the next rubric of the same event and type is version 2', function (): void {
    [, $event, $type, $admin] = rubricScenario();

    $first = app(SaveRubric::class)->handle($admin, $event, rubricData($type));
    $second = app(SaveRubric::class)->handle($admin, $event, rubricData($type));

    expect($first->version)->toBe(1)->and($second->version)->toBe(2);
});

it('CA-011-1: a type from another event of the same organization is rejected', function (): void {
    [$organization, $event, , , $organizer] = rubricScenario();
    $otherEvent = Event::factory()->create(['organization_id' => $organization->id]);
    $foreignType = SubmissionType::factory()->create(['organization_id' => $organization->id, 'event_id' => $otherEvent->id]);

    expect(fn () => app(SaveRubric::class)->handle($organizer, $event, rubricData($foreignType)))
        ->toThrow(ValidationException::class);
    expect(Rubric::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('CA-011-2: rejects invalid criteria and thresholds and stores nothing', function (string $field, Closure $mutate): void {
    [, $event, $type, , $organizer] = rubricScenario();
    $data = $mutate(rubricData($type));

    try {
        app(SaveRubric::class)->handle($organizer, $event, $data);
        $this->fail('Debió rechazarse.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    expect(Rubric::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(RubricCriterion::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'weight zero' => ['criteria.0.weight', fn (array $d): array => data_set($d, 'criteria.0.weight', 0)],
    'weight negative' => ['criteria.0.weight', fn (array $d): array => data_set($d, 'criteria.0.weight', -1)],
    'scale_min equal to scale_max' => ['criteria.0.scale_max', fn (array $d): array => data_set($d, 'criteria.0.scale_max', 1)],
    'scale_min greater than scale_max' => ['criteria.0.scale_max', fn (array $d): array => data_set($d, 'criteria.0.scale_min', 6)],
    'threshold zero' => ['discrepancy_threshold', fn (array $d): array => data_set($d, 'discrepancy_threshold', 0)],
    'threshold negative' => ['discrepancy_threshold', fn (array $d): array => data_set($d, 'discrepancy_threshold', -2)],
    'threshold above the shortest scale (1 to 5 spans 4)' => ['discrepancy_threshold', fn (array $d): array => data_set($d, 'discrepancy_threshold', 5)],
    'min_reviewers zero' => ['min_reviewers', fn (array $d): array => data_set($d, 'min_reviewers', 0)],
    'no criteria' => ['criteria', fn (array $d): array => data_set($d, 'criteria', [])],
    'label missing in the base language' => ['criteria.1.label', fn (array $d): array => data_set($d, 'criteria.1.label', ['en' => 'Clarity'])],
    'unknown kind' => ['criteria.0.kind', fn (array $d): array => data_set($d, 'criteria.0.kind', 'stars')],
    'scale criterion without scale' => ['criteria.0.scale_min', function (array $d): array {
        unset($d['criteria'][0]['scale_min']);

        return $d;
    }],
]);

it('CA-011-2: a threshold equal to the shortest scale span is valid', function (): void {
    [, $event, $type, , $organizer] = rubricScenario();

    $rubric = app(SaveRubric::class)->handle($organizer, $event, rubricData($type, ['discrepancy_threshold' => 4]));

    expect($rubric->discrepancy_threshold)->toBe(4);
});

it('CA-011-2: the database refuses what the validation refuses', function (string $table, array $values): void {
    [$organization, $event, $type] = rubricScenario();
    $rubric = Rubric::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'submission_type_id' => $type->id]);
    $base = $table === 'rubrics'
        ? ['id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'event_id' => $event->id, 'submission_type_id' => $type->id, 'version' => 9]
        : ['id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'rubric_id' => $rubric->id, 'position' => 9, 'label' => '{"es":"x"}'];

    expect(fn () => DB::table($table)->insert([...$base, ...$values]))->toThrow(QueryException::class);
})->with([
    'rubric status outside the list' => ['rubrics', ['status' => 'archived']],
    'rubric min_reviewers zero' => ['rubrics', ['min_reviewers' => 0]],
    'rubric threshold zero' => ['rubrics', ['discrepancy_threshold' => 0]],
    'criterion weight zero' => ['rubric_criteria', ['weight' => 0]],
    'criterion scale_min not below scale_max' => ['rubric_criteria', ['scale_min' => 5, 'scale_max' => 5]],
    'criterion kind outside the list' => ['rubric_criteria', ['kind' => 'stars']],
]);

it('CA-011-2: the database rejects a repeated version and a type from another organization', function (): void {
    [$organization, $event, $type] = rubricScenario();
    $attributes = ['organization_id' => $organization->id, 'event_id' => $event->id, 'submission_type_id' => $type->id, 'version' => 1];
    Rubric::factory()->create($attributes);

    // Cada intento que debe fallar va en su savepoint: en PostgreSQL un error aborta toda la transacción de la prueba.
    expect(fn () => DB::transaction(fn () => Rubric::factory()->create($attributes)))->toThrow(QueryException::class);

    $foreignType = SubmissionType::factory()->create();
    expect(fn () => DB::transaction(fn () => Rubric::factory()->create([
        'organization_id' => $organization->id, 'event_id' => $event->id, 'submission_type_id' => $foreignType->id, 'version' => 2,
    ])))->toThrow(QueryException::class);
});

it('CA-011-2: publishing revalidates the stored content (a rubric with only text criteria is not published)', function (): void {
    [, $event, $type, , $organizer] = rubricScenario();
    $rubric = app(SaveRubric::class)->handle($organizer, $event, rubricData($type, [
        'criteria' => [['label' => ['es' => 'Comentarios'], 'kind' => 'text', 'weight' => 1]],
    ]));

    expect(fn () => app(PublishRubric::class)->handle($organizer, $rubric))->toThrow(ValidationException::class);
    expect($rubric->fresh()->status)->toBe(RubricStatus::Draft);
});

it('CA-011-1: publishing leaves the rubric published and audited; a second publish is rejected', function (): void {
    [$organization, $event, $type, , $organizer] = rubricScenario();
    $rubric = app(SaveRubric::class)->handle($organizer, $event, rubricData($type));

    $published = app(PublishRubric::class)->handle($organizer, $rubric);

    $log = Activity::query()->where('event', 'rubric_published')->sole();
    expect($published->status)->toBe(RubricStatus::Published)
        ->and($log->causer_id)->toBe($organizer->id)
        ->and($log->properties['old']['status'])->toBe('draft')
        ->and($log->properties['new']['status'])->toBe('published')
        ->and($log->properties['organization_id'])->toBe($organization->id);

    expect(fn () => app(PublishRubric::class)->handle($organizer, $published))->toThrow(ValidationException::class);
});

it('CA-011-3: editing a published rubric with recorded reviews creates a new version and leaves the previous intact', function (): void {
    [$organization, $event, $type, , $organizer] = rubricScenario();
    $v1 = app(PublishRubric::class)->handle($organizer, app(SaveRubric::class)->handle($organizer, $event, rubricData($type)));
    RubricUsage::install($v1);

    $edited = rubricData($type, ['min_reviewers' => 3, 'criteria' => [
        ['label' => ['es' => 'Originalidad'], 'kind' => 'scale', 'scale_min' => 1, 'scale_max' => 5, 'weight' => 1],
    ]]);
    $v2 = app(SaveRubric::class)->handle($organizer, $event, $edited, $v1);

    expect($v2->id)->not->toBe($v1->id)
        ->and($v2->version)->toBe(2)
        ->and($v2->status)->toBe(RubricStatus::Draft)
        ->and($v2->min_reviewers)->toBe(3)
        ->and($v2->criteria)->toHaveCount(1);

    $intact = CurrentOrganization::run($organization, fn (): Rubric => Rubric::query()->with('criteria')->findOrFail($v1->id));
    expect($intact->status)->toBe(RubricStatus::Published)
        ->and($intact->min_reviewers)->toBe(2)
        ->and($intact->criteria->map(fn (RubricCriterion $c): string => $c->labelFor('es'))->all())->toBe(['Relevancia', 'Claridad', 'Comentarios'])
        ->and(Activity::query()->where('event', 'rubric_versioned')->count())->toBe(1);
});

it('CA-011-3: without recorded reviews, a draft or a published rubric is edited in place', function (): void {
    [$organization, $event, $type, , $organizer] = rubricScenario();
    $draft = app(SaveRubric::class)->handle($organizer, $event, rubricData($type));

    $edited = app(SaveRubric::class)->handle($organizer, $event, rubricData($type, ['min_reviewers' => 4]), $draft);
    expect($edited->id)->toBe($draft->id)->and($edited->min_reviewers)->toBe(4);

    $published = app(PublishRubric::class)->handle($organizer, $edited);
    $again = app(SaveRubric::class)->handle($organizer, $event, rubricData($type, ['min_reviewers' => 5]), $published);
    expect($again->id)->toBe($published->id)
        ->and($again->status)->toBe(RubricStatus::Published)
        ->and(CurrentOrganization::run($organization, fn (): int => Rubric::query()->count()))->toBe(1)
        ->and(CurrentOrganization::run($organization, fn (): int => RubricCriterion::query()->count()))->toBe(3);
});

it('CA-011-3: editing never changes the type of the rubric', function (): void {
    [$organization, $event, $type, , $organizer] = rubricScenario();
    $otherType = SubmissionType::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);
    $rubric = app(SaveRubric::class)->handle($organizer, $event, rubricData($type));

    $edited = app(SaveRubric::class)->handle($organizer, $event, rubricData($otherType), $rubric);

    expect($edited->submission_type_id)->toBe($type->id);
});

it('CA-011-4: a rubric of another organization is not found by its id (404)', function (): void {
    [$organizationA, $eventA, , , $organizerA] = rubricScenario();
    $rubricOfB = Rubric::factory()->create();

    CurrentOrganization::run($organizationA, function () use ($rubricOfB): void {
        expect(fn () => Rubric::query()->findOrFail($rubricOfB->id))->toThrow(ModelNotFoundException::class);
    });

    $notFound = new ModelNotFoundException;
    $notFound->setModel(Rubric::class, [$rubricOfB->id]);
    expect(app(ExceptionHandler::class)->render(Request::create('/'), $notFound)->getStatusCode())->toBe(404);

    // Una Action recibe el evento de A y la rúbrica de B: no la encuentra.
    $dataType = SubmissionType::factory()->create(['organization_id' => $organizationA->id, 'event_id' => $eventA->id]);
    expect(fn () => app(SaveRubric::class)->handle($organizerA, $eventA, rubricData($dataType), $rubricOfB))
        ->toThrow(ModelNotFoundException::class);
});

it('CA-011-4: a user of A cannot act on a rubric or an event of B', function (): void {
    [, $eventA, $typeA, , $organizerA] = rubricScenario();
    $rubricOfB = Rubric::factory()->create();
    $eventOfB = Event::query()->withoutGlobalScopes()->findOrFail($rubricOfB->event_id);

    expect(fn () => app(PublishRubric::class)->handle($organizerA, $rubricOfB))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveRubric::class)->handle($organizerA, $eventOfB, rubricData($typeA)))->toThrow(AuthorizationException::class)
        ->and($rubricOfB->fresh()->status)->toBe(RubricStatus::Draft);
});

it('CA-011-4: criteria also stay inside their organization', function (): void {
    [$organizationA] = rubricScenario();
    $criterionOfB = RubricCriterion::factory()->create();

    CurrentOrganization::run($organizationA, function () use ($criterionOfB): void {
        expect(RubricCriterion::query()->whereKey($criterionOfB->id)->exists())->toBeFalse();
    });
});

it('CA-011-1: rubrics.manage is the only gate: organizer and admin allowed; reviewer, other organization and no membership denied', function (): void {
    [$organization, $event, $type, $admin, $organizer] = rubricScenario();
    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    $outsider = User::factory()->create();
    $rubric = Rubric::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'submission_type_id' => $type->id]);
    $otherOrganization = Memberships::organization();
    $adminOfOther = Memberships::attach(User::factory()->create(), $otherOrganization, OrganizationRole::OrgAdmin);

    CurrentOrganization::run($organization, function () use ($event, $rubric, $admin, $organizer, $reviewer, $outsider, $adminOfOther): void {
        foreach ([$admin, $organizer] as $allowed) {
            $gate = Gate::forUser($allowed->unsetRelation('roles')->unsetRelation('permissions'));
            expect($gate->allows('viewAny', Rubric::class))->toBeTrue()
                ->and($gate->allows('view', $rubric))->toBeTrue()
                ->and($gate->allows('create', [Rubric::class, $event]))->toBeTrue()
                ->and($gate->allows('update', $rubric))->toBeTrue()
                ->and($gate->allows('publish', $rubric))->toBeTrue();
        }

        foreach ([$reviewer, $outsider, $adminOfOther] as $denied) {
            $gate = Gate::forUser($denied->unsetRelation('roles')->unsetRelation('permissions'));
            expect($gate->allows('viewAny', Rubric::class))->toBeFalse()
                ->and($gate->allows('view', $rubric))->toBeFalse()
                ->and($gate->allows('create', [Rubric::class, $event]))->toBeFalse()
                ->and($gate->allows('update', $rubric))->toBeFalse()
                ->and($gate->allows('publish', $rubric))->toBeFalse();
        }
    });
});

it('CA-011-1: a reviewer cannot save or publish a rubric', function (): void {
    [$organization, $event, $type, , $organizer] = rubricScenario();
    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    $rubric = app(SaveRubric::class)->handle($organizer, $event, rubricData($type));

    expect(fn () => app(SaveRubric::class)->handle($reviewer, $event, rubricData($type)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveRubric::class)->handle($reviewer, $event, rubricData($type), $rubric))->toThrow(AuthorizationException::class)
        ->and(fn () => app(PublishRubric::class)->handle($reviewer, $rubric))->toThrow(AuthorizationException::class);
});
