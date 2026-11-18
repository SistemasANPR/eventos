<?php

declare(strict_types=1);

use App\Actions\Program\CreateActivity;
use App\Actions\Program\DeleteActivity;
use App\Actions\Program\SetActivityPublication;
use App\Actions\Program\UpdateActivity;
use App\Enums\OrganizationRole;
use App\Models\ActivityCategory;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\User;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity as AuditLog;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake(MediaDisk::name());
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array{0: Event, 1: User} evento de una organización nueva y un usuario con el rol indicado en ella */
function activityScenario(OrganizationRole $role = OrganizationRole::OrgAdmin): array
{
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);

    return [Event::factory()->create(['organization_id' => $organization->id]), $user];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function activityData(array $overrides = []): array
{
    return array_replace([
        'title' => 'Apertura del congreso',
        'starts_on' => '2027-03-10',
        'ends_on' => '2027-03-10',
        'starts_at' => '09:00:00',
        'ends_at' => '10:30:00',
    ], $overrides);
}

/** @return list<string> rutas guardadas en el disco de imágenes */
function activityStoredMedia(): array
{
    return MediaDisk::get()->allFiles();
}

/** @return list<string> ids de las categorías de la actividad, en orden */
function activityCategoryIds(EventActivity $activity): array
{
    return CurrentOrganization::run(
        $activity->organization,
        fn (): array => $activity->categories()->orderBy('activity_categories.id')->pluck('activity_categories.id')->all(),
    );
}

function activityCategoryFor(Event $event): ActivityCategory
{
    return CurrentOrganization::run(
        $event->organization,
        fn (): ActivityCategory => ActivityCategory::factory()->create(['event_id' => $event->id]),
    );
}

it('CA-021-4: creates an activity with only the required fields, published and in the event of the organizer', function (): void {
    [$event, $admin] = activityScenario();

    $activity = app(CreateActivity::class)->handle($admin, $event, activityData());

    expect($activity->event_id)->toBe($event->id)
        ->and($activity->organization_id)->toBe($event->organization_id)
        ->and($activity->is_published)->toBeTrue()
        ->and($activity->submission_id)->toBeNull()
        ->and($activity->starts_on?->toDateString())->toBe('2027-03-10')
        ->and($activity->starts_at)->toBe('09:00:00')
        ->and($activity->icon_path)->toBeNull()
        ->and(activityCategoryIds($activity))->toBe([]);
});

it('CA-021-4: name, start and end date and start and end time are each required', function (string $field): void {
    [$event, $admin] = activityScenario();

    try {
        app(CreateActivity::class)->handle($admin, $event, activityData([$field => null]));
        $this->fail('Debió fallar la validación.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe([$field]);
    }

    expect(CurrentOrganization::run($event->organization, fn () => EventActivity::query()->count()))->toBe(0);
})->with(['title', 'starts_on', 'ends_on', 'starts_at', 'ends_at']);

it('CA-021-4: an end before the start is refused, on the same day or on an earlier day', function (array $schedule, string $field): void {
    [$event, $admin] = activityScenario();

    try {
        app(CreateActivity::class)->handle($admin, $event, activityData($schedule));
        $this->fail('Debió fallar la validación.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe([$field]);
    }
})->with([
    'same day earlier time' => [['starts_at' => '12:00', 'ends_at' => '11:00'], 'ends_at'],
    'earlier day' => [['starts_on' => '2027-03-11', 'ends_on' => '2027-03-10'], 'ends_on'],
]);

it('CA-021-4: an end at an earlier time of a later day is valid and times accept HH:MM', function (): void {
    [$event, $admin] = activityScenario();

    $activity = app(CreateActivity::class)->handle($admin, $event, activityData([
        'ends_on' => '2027-03-11', 'starts_at' => '22:00', 'ends_at' => '02:00',
    ]));

    expect($activity->starts_at)->toBe('22:00:00')->and($activity->ends_at)->toBe('02:00:00');
});

it('CA-021-4: the optional fields are saved: summary, description, categories, icon and image', function (): void {
    [$event, $admin] = activityScenario();
    $category = activityCategoryFor($event);

    $activity = app(CreateActivity::class)->handle($admin, $event, activityData([
        'summary' => 'Una línea',
        'description' => '<p>Texto <strong>amplio</strong></p>',
        'category_ids' => [$category->id],
        'icon' => UploadedFile::fake()->image('icono.png', 32, 32),
        'image' => UploadedFile::fake()->image('imagen.jpg', 600, 300),
    ]));

    expect($activity->summary)->toBe('Una línea')
        ->and($activity->description)->toBe('<p>Texto <strong>amplio</strong></p>')
        ->and(activityCategoryIds($activity))->toBe([$category->id])
        ->and($activity->icon_path)->toContain("organizations/{$event->organization_id}/events/{$event->id}/media/icon/")
        ->and($activity->image_path)->toContain('/media/image/')
        ->and(activityStoredMedia())->toHaveCount(2);
});

it('CA-021-6: an activity takes several categories of its own event and refuses one of another event', function (): void {
    [$event, $admin] = activityScenario();
    $other = Event::factory()->create(['organization_id' => $event->organization_id]);
    $first = activityCategoryFor($event);
    $second = activityCategoryFor($event);
    $foreign = activityCategoryFor($other);

    $activity = app(CreateActivity::class)->handle($admin, $event, activityData(['category_ids' => [$first->id, $second->id]]));

    expect(activityCategoryIds($activity))->toHaveCount(2);

    expect(fn () => app(CreateActivity::class)->handle($admin, $event, activityData(['category_ids' => [$foreign->id]])))
        ->toThrow(ValidationException::class);
});

it('CA-021-4: a description of the activity is saved as given, without sanitizing (pending F3b)', function (): void {
    [$event, $admin] = activityScenario();

    $activity = app(CreateActivity::class)->handle($admin, $event, activityData(['description' => '<p>Hola</p>']));

    // F3b aplica `RichTextSanitizer` en la Action; hasta entonces el guardado no toca el HTML.
    expect($activity->description)->toBe('<p>Hola</p>');
});

it('CA-021-3: a bad icon or image is refused with an error on its field and nothing is stored or created', function (string $field, UploadedFile $file): void {
    [$event, $admin] = activityScenario();

    try {
        app(CreateActivity::class)->handle($admin, $event, activityData([$field => $file]));
        $this->fail('Debió fallar la validación.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe([$field]);
    }

    expect(activityStoredMedia())->toBe([])
        ->and(CurrentOrganization::run($event->organization, fn () => EventActivity::query()->count()))->toBe(0);
})->with([
    'icon that is not an image' => ['icon', fn () => UploadedFile::fake()->createWithContent('icono.png', 'no es una imagen')],
    'image of a type not allowed' => ['image', fn () => UploadedFile::fake()->create('imagen.gif', 10, 'image/gif')],
]);

it('CA-021-4: a category that no longer exists refuses the whole creation and stores no file', function (): void {
    [$event, $admin] = activityScenario();
    $category = activityCategoryFor($event);
    $category->delete();

    expect(fn () => app(CreateActivity::class)->handle($admin, $event, activityData([
        'category_ids' => [$category->id],
        'icon' => UploadedFile::fake()->image('icono.png', 32, 32),
    ])))->toThrow(ValidationException::class);

    expect(activityStoredMedia())->toBe([]);
});

it('CA-021-4: updates the activity and requires the four schedule fields to schedule one without date', function (): void {
    [$event, $admin] = activityScenario();
    $activity = CurrentOrganization::run($event->organization, fn () => EventActivity::factory()->unscheduled()->create(['event_id' => $event->id]));

    expect(fn () => app(UpdateActivity::class)->handle($admin, $activity, ['title' => 'Nueva']))->toThrow(ValidationException::class);

    $updated = app(UpdateActivity::class)->handle($admin, $activity, activityData(['title' => 'Programada', 'summary' => 'Resumen']));

    expect($updated->title)->toBe('Programada')
        ->and($updated->isScheduled())->toBeTrue()
        ->and($updated->summary)->toBe('Resumen');
});

it('CA-021-4: replacing or removing an icon deletes the old file after saving and keeps the new one', function (): void {
    [$event, $admin] = activityScenario();
    $activity = app(CreateActivity::class)->handle($admin, $event, activityData([
        'icon' => UploadedFile::fake()->image('uno.png', 32, 32),
        'image' => UploadedFile::fake()->image('imagen.png', 100, 100),
    ]));
    $oldIcon = $activity->icon_path;

    $updated = app(UpdateActivity::class)->handle($admin, $activity, activityData(['icon' => UploadedFile::fake()->image('dos.png', 32, 32)]));

    expect($updated->icon_path)->not->toBe($oldIcon)
        ->and(MediaDisk::get()->exists((string) $oldIcon))->toBeFalse()
        ->and(MediaDisk::get()->exists((string) $updated->icon_path))->toBeTrue()
        ->and(activityStoredMedia())->toHaveCount(2);

    $removed = app(UpdateActivity::class)->handle($admin, $updated, activityData(['remove_image' => true]));

    expect($removed->image_path)->toBeNull()->and(activityStoredMedia())->toHaveCount(1);
});

it('CA-021-4: a failed update keeps the old file and stores no orphan', function (): void {
    [$event, $admin] = activityScenario();
    $activity = app(CreateActivity::class)->handle($admin, $event, activityData(['icon' => UploadedFile::fake()->image('uno.png', 32, 32)]));
    $before = activityStoredMedia();

    expect(fn () => app(UpdateActivity::class)->handle($admin, $activity, activityData([
        'title' => '',
        'icon' => UploadedFile::fake()->image('dos.png', 32, 32),
    ])))->toThrow(ValidationException::class);

    expect(activityStoredMedia())->toBe($before);
});

it('CA-021-6: updating syncs the categories, and leaving them out keeps them', function (): void {
    [$event, $admin] = activityScenario();
    $first = activityCategoryFor($event);
    $second = activityCategoryFor($event);
    $activity = app(CreateActivity::class)->handle($admin, $event, activityData(['category_ids' => [$first->id]]));

    app(UpdateActivity::class)->handle($admin, $activity, activityData());
    expect(activityCategoryIds($activity))->toBe([$first->id]);

    app(UpdateActivity::class)->handle($admin, $activity, activityData(['category_ids' => [$second->id]]));
    expect(activityCategoryIds($activity))->toBe([$second->id]);

    app(UpdateActivity::class)->handle($admin, $activity, activityData(['category_ids' => []]));
    expect(activityCategoryIds($activity))->toBe([]);
});

it('CA-021-16: publishes and unpublishes, clearing the reversal mark, and deleting is logical for org_admin only', function (): void {
    [$event, $admin] = activityScenario();
    $activity = CurrentOrganization::run($event->organization, fn () => EventActivity::factory()->create([
        'event_id' => $event->id, 'is_published' => false, 'hidden_by_reversal_at' => now(),
    ]));

    $published = app(SetActivityPublication::class)->handle($admin, $activity, true);
    expect($published->is_published)->toBeTrue()->and($published->hidden_by_reversal_at)->toBeNull();

    expect(app(SetActivityPublication::class)->handle($admin, $activity, false)->is_published)->toBeFalse();

    app(DeleteActivity::class)->handle($admin, $activity);

    expect(CurrentOrganization::run($event->organization, fn () => EventActivity::query()->count()))->toBe(0)
        ->and(CurrentOrganization::run($event->organization, fn () => EventActivity::query()->withTrashed()->count()))->toBe(1);
});

it('CA-021-15: the organizer creates, edits and publishes but cannot delete; the reviewer can do nothing', function (): void {
    [$event, $organizer] = activityScenario(OrganizationRole::Organizer);
    $activity = app(CreateActivity::class)->handle($organizer, $event, activityData());

    app(UpdateActivity::class)->handle($organizer, $activity, activityData(['title' => 'Editada']));
    app(SetActivityPublication::class)->handle($organizer, $activity, false);

    expect(fn () => app(DeleteActivity::class)->handle($organizer, $activity))->toThrow(AuthorizationException::class);

    $reviewer = Memberships::attach(User::factory()->create(), $event->organization, OrganizationRole::Reviewer);

    foreach ([
        fn () => app(CreateActivity::class)->handle($reviewer, $event, activityData()),
        fn () => app(UpdateActivity::class)->handle($reviewer, $activity, activityData()),
        fn () => app(SetActivityPublication::class)->handle($reviewer, $activity, true),
        fn () => app(DeleteActivity::class)->handle($reviewer, $activity),
    ] as $attempt) {
        expect($attempt)->toThrow(AuthorizationException::class);
    }
});

it('CA-021-17: a user of another organization cannot touch the activities of an event that is not theirs', function (): void {
    [$event] = activityScenario();
    $activity = CurrentOrganization::run($event->organization, fn () => EventActivity::factory()->create(['event_id' => $event->id]));
    $other = Organization::factory()->create();
    $outsider = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::OrgAdmin);

    foreach ([
        fn () => app(CreateActivity::class)->handle($outsider, $event, activityData()),
        fn () => app(UpdateActivity::class)->handle($outsider, $activity, activityData()),
        fn () => app(DeleteActivity::class)->handle($outsider, $activity),
    ] as $attempt) {
        expect($attempt)->toThrow(AuthorizationException::class);
    }

    // Y por identificador, desde otra organización en contexto, la actividad no existe (404).
    expect(fn () => CurrentOrganization::run($other, fn () => EventActivity::query()->findOrFail($activity->id)))
        ->toThrow(ModelNotFoundException::class);
});

it('CA-021-4: creating, updating, publishing and deleting leave an audit trail with ids and no free text', function (): void {
    [$event, $admin] = activityScenario();

    $activity = app(CreateActivity::class)->handle($admin, $event, activityData(['description' => '<p>Dato interno</p>']));
    app(UpdateActivity::class)->handle($admin, $activity, activityData(['title' => 'Otro título']));
    app(SetActivityPublication::class)->handle($admin, $activity, false);
    app(DeleteActivity::class)->handle($admin, $activity);

    $logs = AuditLog::query()->where('subject_id', $activity->id)->orderBy('id')->get();

    expect($logs->pluck('event')->all())->toBe(['activity_created', 'activity_updated', 'activity_unpublished', 'activity_deleted'])
        ->and($logs->first()?->causer_id)->toBe($admin->id)
        ->and($logs->every(fn ($log): bool => $log->properties['organization_id'] === $event->organization_id && $log->properties['event_id'] === $event->id))->toBeTrue()
        ->and(json_encode($logs->pluck('properties')))->not->toContain('Dato interno');
});
