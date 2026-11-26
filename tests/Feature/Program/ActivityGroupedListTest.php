<?php

declare(strict_types=1);

use App\Filament\Resources\Events\Pages\ManageEventActivities;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\User;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 031, F1: la lista de Actividades se agrupa por día y se ordena por hora. No toca datos ni
 * Actions. Estas pruebas corren con APP_ENV=testing (como las del plan 021).
 */

beforeEach(function (): void {
    Storage::fake(MediaDisk::name());
    app()->setLocale('es');
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @return Testable<ManageEventActivities> */
function groupedListPage(Organization $organization, User $user, Event $event): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return Livewire::test(ManageEventActivities::class, ['record' => $event->id]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function groupedActivity(Event $event, array $attributes = [], string $state = 'scheduled'): EventActivity
{
    return CurrentOrganization::run(
        $event->organization,
        fn (): EventActivity => EventActivity::factory()->{$state}()->create(['event_id' => $event->id, ...$attributes]),
    );
}

it('CA-031-1: the list is grouped by day, ordered by start, end and title, with the unscheduled group last', function (): void {
    [$organization, $event, $admin] = formScenario();

    // Se crean desordenadas a propósito: el orden lo pone la consulta, no la inserción.
    groupedActivity($event, ['title' => 'Taller'], 'scheduled');
    groupedActivity($event, ['title' => 'Pendiente'], 'unscheduled');
    groupedActivity($event, ['title' => 'Cierre', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10', 'starts_at' => '16:00:00', 'ends_at' => '17:00:00']);
    groupedActivity($event, ['title' => 'Beta', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10', 'starts_at' => '09:00:00', 'ends_at' => '10:00:00']);
    groupedActivity($event, ['title' => 'Alfa', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10', 'starts_at' => '09:00:00', 'ends_at' => '10:00:00']);
    groupedActivity($event, ['title' => 'Zeta', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10', 'starts_at' => '09:00:00', 'ends_at' => '09:30:00']);
    groupedActivity($event, ['title' => 'Jueves', 'starts_on' => '2027-03-11', 'ends_on' => '2027-03-11', 'starts_at' => '08:00:00', 'ends_at' => '09:00:00']);

    groupedListPage($organization, $admin, $event)
        ->assertSeeInOrder([
            'miércoles, 10 de marzo de 2027',
            'Zeta', 'Alfa', 'Beta', 'Cierre',
            'jueves, 11 de marzo de 2027',
            'Jueves',
            __('program.activities.unscheduled'),
            'Pendiente',
        ]);
});

it('CA-031-1: the group heading uses the language of the user', function (): void {
    [$organization, $event, $admin] = formScenario();
    groupedActivity($event, ['title' => 'Apertura']);
    app()->setLocale('en');

    groupedListPage($organization, $admin, $event)->assertSee('Wednesday, March 10, 2027');
});

it('CA-031-2: an activity that crosses midnight appears only under its start day', function (): void {
    [$organization, $event, $admin] = formScenario();
    groupedActivity($event, [
        'title' => 'Noche',
        'starts_on' => '2027-03-10',
        'ends_on' => '2027-03-11',
        'starts_at' => '22:00:00',
        'ends_at' => '02:00:00',
    ]);

    groupedListPage($organization, $admin, $event)
        ->assertSeeInOrder(['miércoles, 10 de marzo de 2027', 'Noche'])
        ->assertDontSee('jueves, 11 de marzo de 2027');
});

it('CA-031-3: pagination keeps the global day order and the filters keep working', function (): void {
    [$organization, $event, $admin] = formScenario();

    $activities = collect(range(1, 30))->map(fn (int $day): EventActivity => groupedActivity($event, [
        'title' => "Actividad {$day}",
        'starts_on' => sprintf('2027-03-%02d', $day),
        'ends_on' => sprintf('2027-03-%02d', $day),
    ]));
    $draft = groupedActivity($event, ['title' => 'Borrador', 'is_published' => false, 'starts_on' => '2027-03-31', 'ends_on' => '2027-03-31']);
    $pending = groupedActivity($event, ['title' => 'Pendiente'], 'unscheduled');

    // Los 25 primeros por día; los demás quedan en la página siguiente, sin saltarse ninguno.
    groupedListPage($organization, $admin, $event)
        ->assertCanSeeTableRecords($activities->take(25)->all(), inOrder: true)
        ->assertCanNotSeeTableRecords([...$activities->skip(25)->all(), $draft, $pending])
        ->call('setPage', 2)
        ->assertCanSeeTableRecords([...$activities->skip(25)->all(), $draft, $pending], inOrder: true);

    groupedListPage($organization, $admin, $event)
        ->filterTable('is_published', false)
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords($activities->all());

    groupedListPage($organization, $admin, $event)
        ->filterTable('schedule', false)
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$draft, ...$activities->all()]);
});

it('CA-031-1: the grouping can be removed and the list keeps every row', function (): void {
    [$organization, $event, $admin] = formScenario();
    $first = groupedActivity($event, ['title' => 'Apertura']);
    $pending = groupedActivity($event, ['title' => 'Pendiente'], 'unscheduled');

    groupedListPage($organization, $admin, $event)
        ->set('tableGrouping', null)
        ->assertCanSeeTableRecords([$first, $pending])
        ->assertDontSee('miércoles, 10 de marzo de 2027');
});
