<?php

declare(strict_types=1);

use App\Actions\Events\CreateEvent;
use App\Actions\Events\UpdateEvent;
use App\Enums\OrganizationRole;
use App\Enums\TimeFormat;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Events\EventTime;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function timeFormatEventData(array $overrides = []): array
{
    return array_merge([
        'name' => ['es' => 'Congreso Parques 2027'],
        'slug' => 'congreso-2027',
        'type' => 'congress',
        'starts_at' => '2027-03-10 09:00',
        'ends_at' => '2027-03-12 17:00',
        'timezone' => 'America/Mexico_City',
        'locales' => ['es'],
        'default_locale' => 'es',
        'currency' => 'MXN',
    ], $overrides);
}

function timeFormatUser(Organization $organization, OrganizationRole $role): User
{
    return Memberships::attach(User::factory()->create(), $organization, $role);
}

it('CA-022-1: an event created without choosing a format shows 12 hours', function (): void {
    $organization = Memberships::organization();
    $admin = timeFormatUser($organization, OrganizationRole::OrgAdmin);

    $event = app(CreateEvent::class)->handle($admin, $organization, timeFormatEventData());

    expect($event->time_format)->toBe(TimeFormat::TwelveHour)
        ->and(DB::table('events')->where('id', $event->id)->value('time_format'))->toBe('12h')
        ->and(EventTime::for($event)->format)->toBe(TimeFormat::TwelveHour);
});

it('CA-022-1: a row inserted without the column takes 12 hours from the database', function (): void {
    $organization = Organization::factory()->create();

    DB::table('events')->insert([
        'id' => (string) Str::uuid7(),
        'organization_id' => $organization->id,
        'name' => json_encode(['es' => 'Congreso']),
        'slug' => 'congreso-sin-formato',
        'type' => 'congress',
        'starts_at' => '2027-03-10 15:00:00',
        'ends_at' => '2027-03-12 23:00:00',
        'timezone' => 'America/Mexico_City',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('events')->where('slug', 'congreso-sin-formato')->value('time_format'))->toBe('12h');
});

it('CA-022-2: an admin sets 24 hours and the value persists', function (): void {
    $organization = Memberships::organization();
    $admin = timeFormatUser($organization, OrganizationRole::OrgAdmin);
    $event = app(CreateEvent::class)->handle($admin, $organization, timeFormatEventData());

    app(UpdateEvent::class)->handle($admin, $event, timeFormatEventData(['time_format' => '24h']));

    expect($event->fresh()->time_format)->toBe(TimeFormat::TwentyFourHour);
});

it('CA-022-2: editing without sending the format keeps the current one', function (): void {
    $organization = Memberships::organization();
    $admin = timeFormatUser($organization, OrganizationRole::OrgAdmin);
    $event = app(CreateEvent::class)->handle($admin, $organization, timeFormatEventData(['time_format' => '24h']));

    app(UpdateEvent::class)->handle($admin, $event, timeFormatEventData(['city' => 'Puebla']));

    expect($event->fresh()->time_format)->toBe(TimeFormat::TwentyFourHour)
        ->and($event->fresh()->city)->toBe('Puebla');
});

it('CA-022-2: the edit form offers the format, shows the saved one and saves a change', function (): void {
    $organization = Memberships::organization();
    $admin = timeFormatUser($organization, OrganizationRole::OrgAdmin);
    $event = app(CreateEvent::class)->handle($admin, $organization, timeFormatEventData());
    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    Livewire::test(EditEvent::class, ['record' => $event->id])
        ->assertFormSet(['time_format' => TimeFormat::TwelveHour])
        ->fillForm(['time_format' => '24h'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($event->fresh()->time_format)->toBe(TimeFormat::TwentyFourHour);
});

it('CA-022-3: the validation refuses a value other than 12h or 24h', function (string $value): void {
    $organization = Memberships::organization();
    $admin = timeFormatUser($organization, OrganizationRole::OrgAdmin);

    expect(fn () => app(CreateEvent::class)->handle($admin, $organization, timeFormatEventData(['time_format' => $value])))
        ->toThrow(ValidationException::class);
})->with(['13h', '12', 'AM', '24H']);

it('CA-022-3: the database refuses a value other than 12h or 24h', function (): void {
    $organization = Organization::factory()->create();
    $event = Event::factory()->create(['organization_id' => $organization->id]);

    expect(fn () => DB::transaction(fn () => DB::table('events')->where('id', $event->id)->update(['time_format' => '13h'])))
        ->toThrow(QueryException::class);
});

it('CA-022-9: a reviewer cannot change the format and nothing changes', function (): void {
    $organization = Memberships::organization();
    $admin = timeFormatUser($organization, OrganizationRole::OrgAdmin);
    $reviewer = timeFormatUser($organization, OrganizationRole::Reviewer);
    $event = app(CreateEvent::class)->handle($admin, $organization, timeFormatEventData());

    expect(fn () => app(UpdateEvent::class)->handle($reviewer, $event, timeFormatEventData(['time_format' => '24h'])))
        ->toThrow(AuthorizationException::class);

    expect($event->fresh()->time_format)->toBe(TimeFormat::TwelveHour);
});

it('CA-022-10: an admin of another organization cannot change the format of this event', function (): void {
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $adminA = timeFormatUser($organizationA, OrganizationRole::OrgAdmin);
    $adminB = timeFormatUser($organizationB, OrganizationRole::OrgAdmin);
    $eventOfB = app(CreateEvent::class)->handle($adminB, $organizationB, timeFormatEventData());

    expect(fn () => app(UpdateEvent::class)->handle($adminA, $eventOfB, timeFormatEventData(['time_format' => '24h'])))
        ->toThrow(AuthorizationException::class);

    expect($eventOfB->fresh()->time_format)->toBe(TimeFormat::TwelveHour);
});
