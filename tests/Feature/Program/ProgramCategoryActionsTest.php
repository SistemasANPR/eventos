<?php

declare(strict_types=1);

use App\Actions\Program\CreateProgramCategory;
use App\Actions\Program\DeleteProgramCategory;
use App\Actions\Program\UpdateProgramCategory;
use App\Enums\OrganizationRole;
use App\Enums\ProgramCategoryKind;
use App\Models\ActivityCategory;
use App\Models\Event;
use App\Models\SpeakerCategory;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array{0: Event, 1: User} evento de una organización nueva y un usuario con el rol indicado en ella */
function categoryScenario(OrganizationRole $role = OrganizationRole::OrgAdmin): array
{
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);

    return [Event::factory()->create(['organization_id' => $organization->id]), $user];
}

/** @return list<string> nombres del catálogo del evento, en orden de posición */
function categoryNames(Event $event, ProgramCategoryKind $kind): array
{
    return CurrentOrganization::run(
        $event->organization,
        fn (): array => $kind->modelClass()::query()->where('event_id', $event->id)->orderBy('position')->pluck('name')->all(),
    );
}

dataset('catalog kinds', [
    'speaker categories' => [ProgramCategoryKind::Speaker],
    'activity categories' => [ProgramCategoryKind::Activity],
]);

it('CA-021-6: creates categories at the end of the catalog of the event', function (ProgramCategoryKind $kind): void {
    [$event, $admin] = categoryScenario();
    $create = app(CreateProgramCategory::class);

    $first = $create->handle($admin, $event, $kind, ['name' => '  Académico ']);
    $second = $create->handle($admin, $event, $kind, ['name' => 'Industria']);

    expect($first)->toBeInstanceOf($kind->modelClass())
        ->and($first->name)->toBe('Académico')
        ->and([$first->position, $second->position])->toBe([1, 2])
        ->and(categoryNames($event, $kind))->toBe(['Académico', 'Industria']);
})->with('catalog kinds');

it('CA-021-6: speaker and activity catalogs are independent of each other and between events', function (): void {
    [$event, $admin] = categoryScenario();
    $other = Event::factory()->create(['organization_id' => $event->organization_id]);
    $create = app(CreateProgramCategory::class);

    $create->handle($admin, $event, ProgramCategoryKind::Speaker, ['name' => 'Invitado']);
    // El mismo nombre en el otro catálogo y en otro evento de la misma organización es válido.
    $create->handle($admin, $event, ProgramCategoryKind::Activity, ['name' => 'Invitado']);
    $create->handle($admin, $other, ProgramCategoryKind::Speaker, ['name' => 'Invitado']);

    expect(categoryNames($event, ProgramCategoryKind::Speaker))->toBe(['Invitado'])
        ->and(categoryNames($event, ProgramCategoryKind::Activity))->toBe(['Invitado'])
        ->and(categoryNames($other, ProgramCategoryKind::Speaker))->toBe(['Invitado'])
        ->and(categoryNames($other, ProgramCategoryKind::Activity))->toBe([])
        ->and($other->id)->not->toBe($event->id);
});

it('CA-021-6: a repeated name in the same event is rejected without regard to case or spaces', function (ProgramCategoryKind $kind): void {
    [$event, $admin] = categoryScenario();
    $create = app(CreateProgramCategory::class);
    $create->handle($admin, $event, $kind, ['name' => 'Académico']);

    foreach (['Académico', 'ACADÉMICO', 'académico', '  Académico  '] as $name) {
        expect(fn () => $create->handle($admin, $event, $kind, ['name' => $name]))
            ->toThrow(ValidationException::class);
    }

    expect(categoryNames($event, $kind))->toBe(['Académico']);
})->with('catalog kinds');

it('CA-021-6: the name is required and has at most 100 characters', function (ProgramCategoryKind $kind): void {
    [$event, $admin] = categoryScenario();
    $create = app(CreateProgramCategory::class);

    foreach ([[], ['name' => ''], ['name' => '   '], ['name' => str_repeat('a', 101)], ['name' => ['x']]] as $data) {
        expect(fn () => $create->handle($admin, $event, $kind, $data))->toThrow(ValidationException::class);
    }

    expect($create->handle($admin, $event, $kind, ['name' => str_repeat('a', 100)])->name)->toHaveLength(100);
})->with('catalog kinds');

it('CA-021-6: renaming keeps the uniqueness rule and a category does not clash with itself', function (ProgramCategoryKind $kind): void {
    [$event, $admin] = categoryScenario();
    $create = app(CreateProgramCategory::class);
    $update = app(UpdateProgramCategory::class);
    $first = $create->handle($admin, $event, $kind, ['name' => 'Académico']);
    $create->handle($admin, $event, $kind, ['name' => 'Industria']);

    expect(fn () => $update->handle($admin, $first, ['name' => 'industria']))->toThrow(ValidationException::class)
        ->and($update->handle($admin, $first, ['name' => 'ACADÉMICO'])->name)->toBe('ACADÉMICO')
        ->and($update->handle($admin, $first, ['name' => 'Ciencia'])->name)->toBe('Ciencia')
        ->and(categoryNames($event, $kind))->toBe(['Ciencia', 'Industria']);
})->with('catalog kinds');

it('CA-021-6: deleting a category removes only that one', function (ProgramCategoryKind $kind): void {
    [$event, $admin] = categoryScenario();
    $create = app(CreateProgramCategory::class);
    $first = $create->handle($admin, $event, $kind, ['name' => 'Académico']);
    $create->handle($admin, $event, $kind, ['name' => 'Industria']);

    app(DeleteProgramCategory::class)->handle($admin, $first);

    expect(categoryNames($event, $kind))->toBe(['Industria']);
})->with('catalog kinds');

it('CA-021-6: creating, renaming and deleting a category is audited with who and the names', function (): void {
    [$event, $admin] = categoryScenario();

    $category = app(CreateProgramCategory::class)->handle($admin, $event, ProgramCategoryKind::Speaker, ['name' => 'Académico']);
    app(UpdateProgramCategory::class)->handle($admin, $category, ['name' => 'Ciencia']);
    app(DeleteProgramCategory::class)->handle($admin, $category);

    $entries = Activity::query()->whereIn('event', ['speaker_category_created', 'speaker_category_updated', 'speaker_category_deleted'])->orderBy('id')->get();

    expect($entries->pluck('event')->sort()->values()->all())->toBe(['speaker_category_created', 'speaker_category_deleted', 'speaker_category_updated'])
        ->and($entries->every(fn (Activity $entry): bool => $entry->causer_id === $admin->id))->toBeTrue()
        ->and($entries->firstWhere('event', 'speaker_category_updated')?->properties->toArray())
        ->toMatchArray(['old' => ['name' => 'Académico'], 'new' => ['name' => 'Ciencia']]);
});

it('CA-021-15: a reviewer cannot create, rename or delete categories', function (ProgramCategoryKind $kind): void {
    [$event, $reviewer] = categoryScenario(OrganizationRole::Reviewer);
    $admin = Memberships::attach(User::factory()->create(), $event->organization, OrganizationRole::OrgAdmin);
    $category = app(CreateProgramCategory::class)->handle($admin, $event, $kind, ['name' => 'Académico']);

    expect(fn () => app(CreateProgramCategory::class)->handle($reviewer, $event, $kind, ['name' => 'X']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateProgramCategory::class)->handle($reviewer, $category, ['name' => 'X']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteProgramCategory::class)->handle($reviewer, $category))->toThrow(AuthorizationException::class)
        ->and(categoryNames($event, $kind))->toBe(['Académico']);
})->with('catalog kinds');

it('CA-021-15: an organizer creates and renames but cannot delete', function (ProgramCategoryKind $kind): void {
    [$event, $organizer] = categoryScenario(OrganizationRole::Organizer);

    $category = app(CreateProgramCategory::class)->handle($organizer, $event, $kind, ['name' => 'Académico']);
    app(UpdateProgramCategory::class)->handle($organizer, $category, ['name' => 'Ciencia']);

    expect(fn () => app(DeleteProgramCategory::class)->handle($organizer, $category))->toThrow(AuthorizationException::class)
        ->and(categoryNames($event, $kind))->toBe(['Ciencia']);
})->with('catalog kinds');

it('CA-021-15: an admin of another organization cannot touch the categories of this one', function (ProgramCategoryKind $kind): void {
    [$event, $admin] = categoryScenario();
    [, $outsider] = categoryScenario();
    $category = app(CreateProgramCategory::class)->handle($admin, $event, $kind, ['name' => 'Académico']);
    $category = $kind->modelClass()::query()->withoutGlobalScopes()->findOrFail($category->id);

    expect(fn () => app(CreateProgramCategory::class)->handle($outsider, $event, $kind, ['name' => 'X']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateProgramCategory::class)->handle($outsider, $category, ['name' => 'X']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteProgramCategory::class)->handle($outsider, $category))->toThrow(AuthorizationException::class)
        ->and(categoryNames($event, $kind))->toBe(['Académico']);
})->with('catalog kinds');

it('CA-021-17: within the context of one organization the categories of another are not found', function (): void {
    [$eventA] = categoryScenario();
    [$eventB, $adminB] = categoryScenario();
    $speaker = app(CreateProgramCategory::class)->handle($adminB, $eventB, ProgramCategoryKind::Speaker, ['name' => 'Ajena']);
    $activity = app(CreateProgramCategory::class)->handle($adminB, $eventB, ProgramCategoryKind::Activity, ['name' => 'Ajena']);

    CurrentOrganization::set($eventA->organization);

    expect(fn () => SpeakerCategory::query()->findOrFail($speaker->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ActivityCategory::query()->findOrFail($activity->id))->toThrow(ModelNotFoundException::class)
        ->and(SpeakerCategory::query()->count())->toBe(0)
        ->and(ActivityCategory::query()->count())->toBe(0);
});
