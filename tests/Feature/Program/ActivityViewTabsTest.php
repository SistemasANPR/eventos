<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ManageEventActivities;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\User;
use App\Support\Files\MediaDisk;
use App\Support\Program\Agenda\AgendaClash;
use App\Support\Program\Agenda\AgendaClashType;
use App\Support\Program\Agenda\AgendaDay;
use App\Support\Program\Agenda\BuildsAgenda;
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
 * Plan 031, F4 (integración en la pantalla): la lista y la agenda de lectura se alternan con `?vista=lista|agenda` en la
 * misma página de Actividades (DA-1), y la lista marca con texto las actividades que chocan (CA-031-20). Los choques los
 * da `BuildsAgenda`: aquí se usa un doble, porque su cálculo se prueba en el carril de datos. Que el componente esté montado
 * se comprueba por su nombre en la instantánea de Livewire (`program.event-agenda`), que sale en el HTML de la página.
 */

/** Doble de `BuildsAgenda`: devuelve los choques que se le dieron y cuenta cuántas veces se le pidieron. */
final class ViewTabsFakeAgenda implements BuildsAgenda
{
    public int $clashCalls = 0;

    /** @param  array<string, list<AgendaClash>>  $clashes */
    public function __construct(private readonly array $clashes = [], private readonly ?Throwable $failure = null) {}

    public function day(Event $event, ?string $date = null): AgendaDay
    {
        return new AgendaDay(date: $date ?? '2027-03-10', days: ['2027-03-10'], startMinute: 480, endMinute: 1080, columns: []);
    }

    public function clashes(Event $event): array
    {
        $this->clashCalls++;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->clashes;
    }
}

beforeEach(function (): void {
    Storage::fake(MediaDisk::name());
    app()->setLocale('es');
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/**
 * @param  array<string, string>  $query  parámetros de la dirección, como `['vista' => 'agenda']`
 * @return Testable<ManageEventActivities>
 */
function viewTabsPage(Organization $organization, User $user, Event $event, array $query = []): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    /** @var Testable<ManageEventActivities> $component */
    $component = Livewire::withQueryParams($query)->test(ManageEventActivities::class, ['record' => $event->id]);

    return $component;
}

/** @param  array<string, mixed>  $attributes */
function viewTabsActivity(Event $event, string $title, array $attributes = []): EventActivity
{
    return CurrentOrganization::run(
        $event->organization,
        fn (): EventActivity => EventActivity::factory()->scheduled()->create(['event_id' => $event->id, 'title' => $title, ...$attributes]),
    );
}

it('CA-031-19: the page offers the list and the agenda as two links that change only the view in the address', function (): void {
    [$organization, $event, $admin] = formScenario();

    $component = viewTabsPage($organization, $admin, $event);

    $component->assertActionExists('viewList')->assertActionExists('viewAgenda');

    $actions = collect($component->instance()->getCachedHeaderActions())->keyBy(fn ($action): string => $action->getName());

    expect($actions['viewAgenda']->getUrl())->toContain('vista=agenda')
        ->and($actions['viewList']->getUrl())->not->toContain('vista=agenda');
});

it('CA-031-19: with no view, or with the list, the page shows the table and does not mount the agenda', function (): void {
    [$organization, $event, $admin] = formScenario();
    $activity = viewTabsActivity($event, 'Charla de la lista');

    viewTabsPage($organization, $admin, $event)
        ->assertCanSeeTableRecords([$activity])
        ->assertDontSeeHtml('program.event-agenda');

    viewTabsPage($organization, $admin, $event, ['vista' => 'lista'])
        ->assertCanSeeTableRecords([$activity])
        ->assertDontSeeHtml('program.event-agenda');
});

it('CA-031-19: with the agenda view the page mounts the agenda component instead of the table', function (): void {
    [$organization, $event, $admin] = formScenario();
    viewTabsActivity($event, 'Charla de la agenda');

    viewTabsPage($organization, $admin, $event, ['vista' => 'agenda'])
        ->assertSeeHtml('program.event-agenda')
        ->assertDontSeeHtml('fi-ta-table');

    viewTabsPage($organization, $admin, $event)->assertSeeHtml('fi-ta-table');
});

it('CA-031-19: any other value of the view falls back to the list', function (): void {
    [$organization, $event, $admin] = formScenario();
    $activity = viewTabsActivity($event, 'Charla de la lista');

    viewTabsPage($organization, $admin, $event, ['vista' => 'calendario'])
        ->assertCanSeeTableRecords([$activity])
        ->assertDontSeeHtml('program.event-agenda');
});

it('CA-031-19: the header actions of the list stay in both views', function (): void {
    [$organization, $event, $admin] = formScenario();

    foreach (['lista', 'agenda'] as $view) {
        viewTabsPage($organization, $admin, $event, ['vista' => $view])
            ->assertActionExists('createActivity')
            ->assertActionExists('rooms');
    }
});

it('CA-031-24: a reviewer reaches neither the list nor the agenda of the activities page', function (): void {
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);
    Auth::login($reviewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    $url = EventResource::getUrl('activities', ['record' => $event], panel: 'admin', tenant: $organization);

    expect(ManageEventActivities::canAccess(['record' => $event]))->toBeFalse()
        ->and($this->actingAs($reviewer)->get($url.'?vista=agenda')->status())->toBeIn([403, 404])
        ->and($this->actingAs($reviewer)->get($url)->status())->toBeIn([403, 404]);
});

it('CA-031-20: the list marks with text the activities that clash, naming the other activity', function (): void {
    [$organization, $event, $admin] = formScenario();
    $first = viewTabsActivity($event, 'Primera charla');
    $second = viewTabsActivity($event, 'Segunda charla', ['starts_at' => '10:30:00', 'ends_at' => '11:30:00']);
    $alone = viewTabsActivity($event, 'Charla sola', ['starts_at' => '15:00:00', 'ends_at' => '16:00:00']);

    app()->instance(BuildsAgenda::class, new ViewTabsFakeAgenda([
        $first->id => [new AgendaClash(AgendaClashType::Speaker, $second->id, 'Segunda charla', 'Ada Lovelace')],
        $second->id => [
            new AgendaClash(AgendaClashType::Speaker, $first->id, 'Primera charla', 'Ada Lovelace'),
            new AgendaClash(AgendaClashType::Room, $first->id, 'Primera charla'),
        ],
    ]));

    $component = viewTabsPage($organization, $admin, $event);

    $component
        ->assertTableColumnStateSet('clashes', [__('program.activities.clash.speaker', ['speaker' => 'Ada Lovelace', 'title' => 'Segunda charla'])], $first)
        ->assertTableColumnStateSet('clashes', [
            __('program.activities.clash.speaker', ['speaker' => 'Ada Lovelace', 'title' => 'Primera charla']),
            __('program.activities.clash.room', ['title' => 'Primera charla']),
        ], $second)
        ->assertTableColumnStateSet('clashes', null, $alone)
        ->assertSee('Choque de ponente')
        ->assertSee('Choque de sala');
});

it('CA-031-20: the clashes are asked for once per render, not once per row', function (): void {
    [$organization, $event, $admin] = formScenario();

    foreach (range(1, 6) as $n) {
        viewTabsActivity($event, "Charla {$n}", ['starts_at' => sprintf('%02d:00:00', 7 + $n), 'ends_at' => sprintf('%02d:45:00', 7 + $n)]);
    }

    $fake = new ViewTabsFakeAgenda;
    app()->instance(BuildsAgenda::class, $fake);

    viewTabsPage($organization, $admin, $event)->assertOk();

    expect($fake->clashCalls)->toBe(1);
});

it('CA-031-20: a failure of the clash service is not hidden as a list without clashes', function (): void {
    [$organization, $event, $admin] = formScenario();
    viewTabsActivity($event, 'Cualquier charla');

    app()->instance(BuildsAgenda::class, new ViewTabsFakeAgenda(failure: new RuntimeException('falla de base de datos')));

    $caught = null;

    try {
        viewTabsPage($organization, $admin, $event);
    } catch (Throwable $exception) {
        $caught = $exception;
    }

    // Livewire envuelve el error de la vista (`ViewException`): la causa original debe seguir en la cadena.
    while ($caught !== null && ! ($caught instanceof RuntimeException && $caught->getMessage() === 'falla de base de datos')) {
        $caught = $caught->getPrevious();
    }

    expect($caught)->not->toBeNull();
});

it('CA-031-20: the clash column cannot be hidden by mistake from the column picker', function (): void {
    [$organization, $event, $admin] = formScenario();
    app()->instance(BuildsAgenda::class, new ViewTabsFakeAgenda);

    $column = viewTabsPage($organization, $admin, $event)->instance()->getTable()->getColumn('clashes');

    expect($column->isToggleable())->toBeFalse();
});
