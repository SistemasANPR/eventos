<?php

declare(strict_types=1);

use App\Actions\Events\ArchiveEvent;
use App\Actions\Events\CreateEvent;
use App\Actions\Events\PublishEvent;
use App\Actions\Events\UpdateEvent;
use App\Enums\EventStatus;
use App\Enums\OrganizationRole;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Datos del formulario: las fechas viajan como hora LOCAL del evento (`Y-m-d H:i`).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function eventData(array $overrides = []): array
{
    return array_merge([
        'name' => ['es' => 'Congreso Parques 2027', 'en' => 'Parks Congress 2027'],
        'description' => ['es' => 'Descripción'],
        'slug' => 'congreso-2027',
        'type' => 'congress',
        'starts_at' => '2027-03-10 09:00',
        'ends_at' => '2027-03-12 17:00',
        'timezone' => 'America/Mexico_City',
        'venue_name' => 'Centro de Convenciones',
        'venue_address' => 'Av. Reforma 1',
        'city' => 'Ciudad de México',
        'locales' => ['es', 'en'],
        'default_locale' => 'es',
        'currency' => 'MXN',
    ], $overrides);
}

function userWithRole(Organization $organization, OrganizationRole $role): User
{
    return Memberships::attach(User::factory()->create(), $organization, $role);
}

it('CA-003-1: creates the event in draft for the organization of the user and audits it', function (): void {
    $organization = Memberships::organization();
    $organizer = userWithRole($organization, OrganizationRole::Organizer);

    $event = app(CreateEvent::class)->handle($organizer, $organization, eventData());

    expect($event->organization_id)->toBe($organization->id)
        ->and($event->status)->toBe(EventStatus::Draft)
        ->and($event->getTranslation('name', 'es'))->toBe('Congreso Parques 2027');

    $entry = Activity::query()->where('event', 'event_created')->sole();
    expect($entry->causer_id)->toBe($organizer->id)
        ->and($entry->subject_id)->toBe($event->id);
});

it('CA-003-2: refuses a repeated slug inside one organization but allows it in another', function (): void {
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $adminA = userWithRole($organizationA, OrganizationRole::OrgAdmin);
    $adminB = userWithRole($organizationB, OrganizationRole::OrgAdmin);

    app(CreateEvent::class)->handle($adminA, $organizationA, eventData());

    expect(fn () => app(CreateEvent::class)->handle($adminA, $organizationA, eventData()))
        ->toThrow(ValidationException::class);
    expect(app(CreateEvent::class)->handle($adminB, $organizationB, eventData()))->toBeInstanceOf(Event::class);
});

it('CA-003-3: refuses invalid data with the error of each field and saves nothing', function (string $field, array $override): void {
    $organization = Memberships::organization();
    $admin = userWithRole($organization, OrganizationRole::OrgAdmin);

    try {
        app(CreateEvent::class)->handle($admin, $organization, eventData($override));
        $this->fail('Se esperaba ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    expect(CurrentOrganization::run($organization, fn () => Event::query()->count()))->toBe(0);
})->with([
    'ends before it starts' => ['ends_at', ['ends_at' => '2027-03-09 09:00']],
    'not an IANA time zone' => ['timezone', ['timezone' => 'Mexico/Centro']],
    'language outside es, en and pt' => ['locales.1', ['locales' => ['es', 'fr']]],
    'default language not among the languages' => ['default_locale', ['default_locale' => 'pt']],
    'invalid currency' => ['currency', ['currency' => 'EUR']],
    'invalid type' => ['type', ['type' => 'concierto']],
    'invalid slug' => ['slug', ['slug' => 'Congreso 2027']],
    'no name in any language' => ['name', ['name' => []]],
    'malformed date' => ['starts_at', ['starts_at' => '10/03/2027']],
]);

it('CA-003-4: stores the instants in UTC from the local time of the event time zone', function (): void {
    $organization = Memberships::organization();
    $admin = userWithRole($organization, OrganizationRole::OrgAdmin);

    $event = app(CreateEvent::class)->handle($admin, $organization, eventData());

    // 09:00 en Ciudad de México (UTC-6) son las 15:00 UTC.
    expect($event->starts_at->utc()->format('Y-m-d H:i'))->toBe('2027-03-10 15:00')
        ->and($event->ends_at->utc()->format('Y-m-d H:i'))->toBe('2027-03-12 23:00');
});

it('CA-003-4: a different time zone gives different UTC instants', function (): void {
    $organization = Memberships::organization();
    $admin = userWithRole($organization, OrganizationRole::OrgAdmin);

    $event = app(CreateEvent::class)->handle($admin, $organization, eventData(['timezone' => 'Europe/Madrid']));

    expect($event->starts_at->utc()->format('Y-m-d H:i'))->toBe('2027-03-10 08:00');
});

it('CA-003-6: a reviewer cannot create or update events and nothing changes', function (): void {
    $organization = Memberships::organization();
    $reviewer = userWithRole($organization, OrganizationRole::Reviewer);
    $admin = userWithRole($organization, OrganizationRole::OrgAdmin);
    $event = app(CreateEvent::class)->handle($admin, $organization, eventData());

    expect(fn () => app(CreateEvent::class)->handle($reviewer, $organization, eventData(['slug' => 'otro'])))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateEvent::class)->handle($reviewer, $event, eventData(['city' => 'Otra'])))
        ->toThrow(AuthorizationException::class);

    expect(CurrentOrganization::run($organization, fn () => Event::query()->count()))->toBe(1)
        ->and($event->fresh()->city)->toBe('Ciudad de México');
});

it('CA-003-6: an organizer cannot publish or archive', function (): void {
    $organization = Memberships::organization();
    $organizer = userWithRole($organization, OrganizationRole::Organizer);
    $event = app(CreateEvent::class)->handle($organizer, $organization, eventData());

    expect(fn () => app(PublishEvent::class)->handle($organizer, $event))->toThrow(AuthorizationException::class);
    expect($event->fresh()->status)->toBe(EventStatus::Draft);

    $event->forceFill(['status' => EventStatus::Published])->save();

    expect(fn () => app(ArchiveEvent::class)->handle($organizer, $event->fresh()))->toThrow(AuthorizationException::class);
    expect($event->fresh()->status)->toBe(EventStatus::Published);
});

it('CA-003-6: a deactivated member cannot manage events even with the role', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin, 'deactivated');

    expect(fn () => app(CreateEvent::class)->handle($admin, $organization, eventData()))
        ->toThrow(AuthorizationException::class);
});

it('CA-003-5: an admin of another organization cannot update an event it does not own', function (): void {
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $adminA = userWithRole($organizationA, OrganizationRole::OrgAdmin);
    $adminB = userWithRole($organizationB, OrganizationRole::OrgAdmin);
    $eventOfB = app(CreateEvent::class)->handle($adminB, $organizationB, eventData());

    expect(fn () => app(UpdateEvent::class)->handle($adminA, $eventOfB, eventData(['city' => 'Hackeada'])))
        ->toThrow(AuthorizationException::class);

    expect($eventOfB->fresh()->city)->toBe('Ciudad de México');
});

it('CA-003-7: publishes a complete draft', function (): void {
    $organization = Memberships::organization();
    $admin = userWithRole($organization, OrganizationRole::OrgAdmin);
    $event = app(CreateEvent::class)->handle($admin, $organization, eventData());

    $published = app(PublishEvent::class)->handle($admin, $event);

    expect($published->status)->toBe(EventStatus::Published)
        ->and(Activity::query()->where('event', 'event_published')->count())->toBe(1);
});

it('CA-003-7: refuses to publish without the name in the default language and names the field', function (): void {
    $organization = Memberships::organization();
    $admin = userWithRole($organization, OrganizationRole::OrgAdmin);
    $event = app(CreateEvent::class)->handle($admin, $organization, eventData([
        'name' => ['es' => 'Solo en español'],
        'locales' => ['es', 'en'],
        'default_locale' => 'en',
    ]));

    try {
        app(PublishEvent::class)->handle($admin, $event);
        $this->fail('Se esperaba ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('name.en');
    }

    expect($event->fresh()->status)->toBe(EventStatus::Draft);
});

it('CA-003-8: archives a published event and keeps its data', function (): void {
    $organization = Memberships::organization();
    $admin = userWithRole($organization, OrganizationRole::OrgAdmin);
    $event = app(PublishEvent::class)->handle(
        $admin,
        app(CreateEvent::class)->handle($admin, $organization, eventData()),
    );

    $archived = app(ArchiveEvent::class)->handle($admin, $event);

    expect($archived->status)->toBe(EventStatus::Archived)
        ->and($archived->deleted_at)->toBeNull()
        ->and($archived->getTranslation('name', 'es'))->toBe('Congreso Parques 2027')
        ->and(Activity::query()->where('event', 'event_archived')->count())->toBe(1);
});

it('CA-003-8: only a published event can be archived and only a draft can be published', function (): void {
    $organization = Memberships::organization();
    $admin = userWithRole($organization, OrganizationRole::OrgAdmin);
    $draft = app(CreateEvent::class)->handle($admin, $organization, eventData());

    expect(fn () => app(ArchiveEvent::class)->handle($admin, $draft))->toThrow(ValidationException::class);

    $published = app(PublishEvent::class)->handle($admin, $draft);

    expect(fn () => app(PublishEvent::class)->handle($admin, $published))->toThrow(ValidationException::class);
});

it('CA-003-9: changing the slug of a published event needs explicit confirmation', function (): void {
    $organization = Memberships::organization();
    $admin = userWithRole($organization, OrganizationRole::OrgAdmin);
    $event = app(PublishEvent::class)->handle(
        $admin,
        app(CreateEvent::class)->handle($admin, $organization, eventData()),
    );

    try {
        app(UpdateEvent::class)->handle($admin, $event, eventData(['slug' => 'nuevo-slug']));
        $this->fail('Se esperaba ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('slug');
    }

    expect($event->fresh()->slug)->toBe('congreso-2027');

    $updated = app(UpdateEvent::class)->handle($admin, $event, eventData(['slug' => 'nuevo-slug']), confirmSlugChange: true);

    expect($updated->slug)->toBe('nuevo-slug');

    $entry = Activity::query()->where('event', 'event_slug_changed')->sole();
    expect($entry->properties->all())->toMatchArray(['from' => 'congreso-2027', 'to' => 'nuevo-slug']);
});

it('CA-003-9: the slug of a draft changes freely and an unchanged slug needs no confirmation', function (): void {
    $organization = Memberships::organization();
    $admin = userWithRole($organization, OrganizationRole::OrgAdmin);
    $draft = app(CreateEvent::class)->handle($admin, $organization, eventData());

    $renamed = app(UpdateEvent::class)->handle($admin, $draft, eventData(['slug' => 'otro-slug']));
    $published = app(PublishEvent::class)->handle($admin, $renamed);
    $edited = app(UpdateEvent::class)->handle($admin, $published, eventData(['slug' => 'otro-slug', 'city' => 'Puebla']));

    expect($renamed->slug)->toBe('otro-slug')
        ->and($edited->city)->toBe('Puebla')
        ->and(Activity::query()->where('event', 'event_slug_changed')->count())->toBe(0);
});

it('CA-003-3: updating validates like creating and an own slug is not a duplicate', function (): void {
    $organization = Memberships::organization();
    $admin = userWithRole($organization, OrganizationRole::OrgAdmin);
    $event = app(CreateEvent::class)->handle($admin, $organization, eventData());

    expect(fn () => app(UpdateEvent::class)->handle($admin, $event, eventData(['ends_at' => '2027-03-01 00:00'])))
        ->toThrow(ValidationException::class);

    $same = app(UpdateEvent::class)->handle($admin, $event, eventData(['venue_name' => 'Nueva sede']));

    expect($same->venue_name)->toBe('Nueva sede');
});
