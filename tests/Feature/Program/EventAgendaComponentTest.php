<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\EventResource;
use App\Livewire\Program\EventAgenda;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Program\Agenda\AgendaCell;
use App\Support\Program\Agenda\AgendaClash;
use App\Support\Program\Agenda\AgendaClashType;
use App\Support\Program\Agenda\AgendaColumn;
use App\Support\Program\Agenda\AgendaColumnKind;
use App\Support\Program\Agenda\AgendaDay;
use App\Support\Program\Agenda\AgendaPending;
use App\Support\Program\Agenda\BuildsAgenda;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 031, F4 (carril de la cuadrícula): el componente Livewire de la agenda de lectura. Se prueba contra un doble de
 * `BuildsAgenda` (la lectura real de datos es de otro carril): lo que dibuja, sus estados y quién puede abrirlo.
 * Cada prueba usa un solo rol: la caché de permisos de Spatie se comparte dentro del proceso.
 */

beforeEach(function (): void {
    app()->setLocale('es');
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** Doble de la lectura de datos: devuelve lo que se le da y recuerda el día que se le pidió. */
final class FakeAgenda implements BuildsAgenda
{
    /** @var list<string|null> */
    public array $requested = [];

    /** @param  Closure(?string): AgendaDay  $factory */
    public function __construct(private readonly Closure $factory) {}

    public function day(Event $event, ?string $date = null): AgendaDay
    {
        $this->requested[] = $date;

        return ($this->factory)($date);
    }

    public function clashes(Event $event): array
    {
        return [];
    }
}

/** @param  Closure(?string): AgendaDay  $factory */
function fakeAgenda(Closure $factory): FakeAgenda
{
    $fake = new FakeAgenda($factory);
    app()->instance(BuildsAgenda::class, $fake);

    return $fake;
}

/**
 * @param  list<AgendaCell>|null  $cells  sin dato, una actividad de ejemplo; `[]` es un día sin actividades
 * @param  list<AgendaPending>  $pending
 * @param  list<AgendaColumn>|null  $columns
 */
function agendaDay(?array $cells = null, array $pending = [], string $date = '2027-03-10', ?array $columns = null): AgendaDay
{
    $cells ??= [new AgendaCell('act-default', 'Actividad de ejemplo', 9 * 60, 10 * 60)];

    return new AgendaDay(
        date: $date,
        days: ['2027-03-10', '2027-03-11', '2027-03-12'],
        startMinute: 8 * 60,
        endMinute: 18 * 60,
        columns: $columns ?? [
            new AgendaColumn('room-a', AgendaColumnKind::Room, 'Auditorio', 300, $cells),
            new AgendaColumn('room-b', AgendaColumnKind::Room, 'Sala Reforma'),
            new AgendaColumn('external', AgendaColumnKind::External),
            new AgendaColumn('none', AgendaColumnKind::None),
        ],
        pending: $pending,
    );
}

/** @return Testable<EventAgenda> */
function agendaPage(Organization $organization, User $user, Event $event): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return Livewire::test(EventAgenda::class, ['eventId' => $event->id]);
}

it('CA-031-24: an org admin opens the agenda', function (): void {
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay());
    [$organization, $event, $admin] = formScenario();

    agendaPage($organization, $admin, $event)->assertOk()->assertSee('Auditorio');
});

it('CA-031-24: an organizer opens the agenda', function (): void {
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay());
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);

    agendaPage($organization, $organizer, $event)->assertOk()->assertSee('Auditorio');
});

it('CA-031-24: a reviewer cannot open the agenda even calling the component directly', function (): void {
    $this->withoutExceptionHandling();
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay());
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);

    expect(fn () => agendaPage($organization, $reviewer, $event))->toThrow(AuthorizationException::class);
});

it('CA-031-24: a user without membership in the organization cannot open the agenda', function (): void {
    $this->withoutExceptionHandling();
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay());
    [$organization, $event] = formScenario();
    $stranger = User::factory()->create();

    expect(fn () => agendaPage($organization, $stranger, $event))->toThrow(AuthorizationException::class);
});

it('CA-031-24: nobody without a session can open the agenda', function (): void {
    $this->withoutExceptionHandling();
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay());
    [$organization, $event] = formScenario();
    Auth::logout();
    CurrentOrganization::set($organization);

    expect(fn () => Livewire::test(EventAgenda::class, ['eventId' => $event->id]))->toThrow(AuthorizationException::class);
});

it('CA-031-24: access is checked again on every later action, not only when mounting', function (): void {
    $this->withoutExceptionHandling();
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay());
    [$organization, $event, $admin] = formScenario();
    $component = agendaPage($organization, $admin, $event);

    Auth::logout();

    expect(fn () => $component->call('selectDay', '2027-03-11'))->toThrow(AuthorizationException::class);
});

it('CA-031-24: an event of another organization answers as not found', function (): void {
    $this->withoutExceptionHandling();
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay());
    [$organization, , $admin] = formScenario();
    $foreign = Event::factory()->create();

    expect(fn () => agendaPage($organization, $admin, $foreign))->toThrow(ModelNotFoundException::class);
});

it('CA-031-19: draws one column per room in the given order, then the external and the no-place columns, with their headers', function (): void {
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay());
    [$organization, $event, $admin] = formScenario();

    $component = agendaPage($organization, $admin, $event);
    $component
        ->assertSeeInOrder(['Auditorio', 'Sala Reforma', __('program.agenda.column_external'), __('program.agenda.column_none')])
        ->assertSee(__('program.agenda.capacity', ['count' => 300]))
        ->assertSeeHtml('data-agenda-column="room-a"')
        ->assertSeeHtml('data-agenda-column="external"')
        ->assertSeeHtml('data-agenda-column="none"');

    expect($component->html())->not->toContain('data-agenda-column="room-c"');
});

it('CA-031-19: each activity is drawn in its column at its position with its title, hours and speakers', function (): void {
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay([
        new AgendaCell('act-1', 'Inauguración y bienvenida', 9 * 60, 10 * 60 + 30, speakers: ['Ada Lovelace', 'Grace Hopper'], quota: 40),
    ]));
    [$organization, $event, $admin] = formScenario();

    $html = agendaPage($organization, $admin, $event)
        ->assertSee('Inauguración y bienvenida')
        ->assertSee('Ada Lovelace')
        ->assertSee('Grace Hopper')
        ->assertSee(__('program.agenda.quota', ['count' => 40]))
        ->assertSee('9:00 a. m.')
        ->assertSee('10:30 a. m.')
        ->html();

    // Empieza una hora después del inicio de la cuadrícula (08:00): 4 filas de 15 min; dura 90 min: 6 filas.
    expect($html)->toContain('data-agenda-cell="act-1"')
        ->and($html)->toMatch('/data-agenda-cell="act-1"[^>]*style="[^"]*--agenda-start:\s*4\b/')
        ->and($html)->toMatch('/data-agenda-cell="act-1"[^>]*style="[^"]*--agenda-span:\s*6\b/');
});

it('CA-031-19: the hours follow the 24-hour format of the event and the zone of the event is shown', function (): void {
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay([new AgendaCell('act-1', 'Taller', 14 * 60, 15 * 60 + 30)]));
    [$organization, $event, $admin] = formScenario();
    DB::table('events')->where('id', $event->id)->update(['time_format' => '24h']);

    agendaPage($organization, $admin, $event)
        ->assertSee('14:00')
        ->assertSee('15:30')
        ->assertDontSee('2:00 p. m.')
        ->assertSee($event->timezone);
});

it('CA-031-19: the day selector lists the days, marks the current one and changes the day without reloading', function (): void {
    $fake = fakeAgenda(fn (?string $date): AgendaDay => agendaDay(date: $date ?? '2027-03-10'));
    [$organization, $event, $admin] = formScenario();

    $component = agendaPage($organization, $admin, $event)
        ->assertSeeHtml('wire:click="selectDay(\'2027-03-11\')"')
        ->assertSeeHtml('aria-current="date"');

    expect($component->html())->toMatch('/aria-current="date"[^>]*>[^<]*(mié|miércoles)/iu');

    $component->call('selectDay', '2027-03-12')->assertSet('date', '2027-03-12');

    expect(end($fake->requested))->toBe('2027-03-12');
});

it('CA-031-19: the day comes from the address (?dia=) and a malformed day is ignored', function (): void {
    $fake = fakeAgenda(fn (?string $date): AgendaDay => agendaDay(date: $date ?? '2027-03-10'));
    [$organization, $event, $admin] = formScenario();

    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    Livewire::withQueryParams(['dia' => '2027-03-11'])->test(EventAgenda::class, ['eventId' => $event->id])->assertSet('date', '2027-03-11');
    expect(end($fake->requested))->toBe('2027-03-11');

    Livewire::withQueryParams(['dia' => '<script>'])->test(EventAgenda::class, ['eventId' => $event->id]);
    expect(end($fake->requested))->toBeNull();
});

it('CA-031-20: a speaker clash and a room clash show a badge with text that names the other activity', function (): void {
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay([
        new AgendaCell('act-1', 'Panel de financiamiento', 10 * 60, 11 * 60, clashes: [
            new AgendaClash(AgendaClashType::Speaker, 'act-2', 'Taller de diseño', 'Ada Lovelace'),
            new AgendaClash(AgendaClashType::Room, 'act-3', 'Mesa redonda'),
        ]),
        new AgendaCell('act-4', 'Sin choque', 14 * 60, 15 * 60),
    ]));
    [$organization, $event, $admin] = formScenario();

    $component = agendaPage($organization, $admin, $event)
        ->assertSee(__('program.agenda.clash_speaker', ['title' => 'Taller de diseño', 'speaker' => 'Ada Lovelace']))
        ->assertSee(__('program.agenda.clash_room', ['title' => 'Mesa redonda']));

    // La tarjeta sin choques no lleva ninguna insignia de choque.
    expect(substr_count($component->html(), 'data-agenda-clash'))->toBe(2);
});

it('CA-031-23: an activity of several days or crossing midnight is drawn under its start day with an indicator', function (): void {
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay([
        new AgendaCell('act-1', 'Fiesta de clausura', 22 * 60, 24 * 60, continuesNextDay: true, isMultiDay: true),
        new AgendaCell('act-2', 'Recorrido de dos días', 9 * 60, 10 * 60, isMultiDay: true),
    ]));
    [$organization, $event, $admin] = formScenario();

    agendaPage($organization, $admin, $event)
        ->assertSee(__('program.agenda.continues_next_day'))
        ->assertSee(__('program.agenda.multi_day'));
});

it('CA-031-20: an activity hidden by reversal and an unpublished one say so with text', function (): void {
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay([
        new AgendaCell('act-1', 'Oculta', 9 * 60, 10 * 60, isPublished: false, isHiddenByReversal: true),
        new AgendaCell('act-2', 'Borrador', 11 * 60, 12 * 60, isPublished: false),
    ]));
    [$organization, $event, $admin] = formScenario();

    $component = agendaPage($organization, $admin, $event)
        ->assertSee(__('program.agenda.hidden_by_reversal'))
        ->assertSee(__('program.agenda.unpublished'));

    expect(substr_count($component->html(), __('program.agenda.unpublished')))->toBeGreaterThanOrEqual(1);
});

it('CA-031-19: an external place is named on the card inside the external column', function (): void {
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay(columns: [
        new AgendaColumn('external', AgendaColumnKind::External, null, null, [
            new AgendaCell('act-1', 'Taller en el parque', 9 * 60, 11 * 60, lane: 0, lanes: 2, placeName: 'Parque Lineal'),
            new AgendaCell('act-2', 'Otro taller', 9 * 60, 11 * 60, lane: 1, lanes: 2, placeName: 'Plaza Central'),
        ]),
        new AgendaColumn('none', AgendaColumnKind::None),
    ]));
    [$organization, $event, $admin] = formScenario();

    $html = agendaPage($organization, $admin, $event)->assertSee('Parque Lineal')->assertSee('Plaza Central')->html();

    expect($html)->toMatch('/data-agenda-cell="act-2"[^>]*style="[^"]*--agenda-lane:\s*1\b/')
        ->and($html)->toMatch('/data-agenda-cell="act-2"[^>]*style="[^"]*--agenda-lanes:\s*2\b/');
});

it('CA-031-25: with no places it shows only the no-place column and invites to create places with a link', function (): void {
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay([], [], '2027-03-10', [new AgendaColumn('none', AgendaColumnKind::None, null, null, [new AgendaCell('act-1', 'Plenaria', 9 * 60, 10 * 60)])]));
    [$organization, $event, $admin] = formScenario();

    agendaPage($organization, $admin, $event)
        ->assertSee(__('program.agenda.no_places_title'))
        ->assertSee(__('program.agenda.no_places_link'))
        ->assertSeeHtml('href="'.EventResource::getUrl('rooms', ['record' => $event]).'"')
        ->assertSee('Plenaria');
});

it('CA-031-25: a day without activities shows the empty state of the day and keeps the pending panel', function (): void {
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay([], [new AgendaPending('p-1', 'Charla por programar', true, ['Ada Lovelace'])]));
    [$organization, $event, $admin] = formScenario();

    agendaPage($organization, $admin, $event)
        ->assertSee(__('program.agenda.empty_day'))
        ->assertSee(__('program.agenda.pending_title'))
        ->assertSee('Charla por programar')
        ->assertSee('Ada Lovelace');
});

it('CA-031-25: an event with no activities at all shows the general empty state', function (): void {
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay([]));
    [$organization, $event, $admin] = formScenario();

    agendaPage($organization, $admin, $event)
        ->assertSee(__('program.agenda.empty_general_title'))
        ->assertDontSee(__('program.agenda.empty_day'));
});

it('CA-031-25: the pending panel lists the unscheduled activities of the event with their speakers', function (): void {
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay([new AgendaCell('act-1', 'Con horario', 9 * 60, 10 * 60)], [
        new AgendaPending('p-1', 'Sin horario uno', true, ['Grace Hopper']),
        new AgendaPending('p-2', 'Sin horario dos', false),
    ]));
    [$organization, $event, $admin] = formScenario();

    agendaPage($organization, $admin, $event)
        ->assertSee(__('program.agenda.pending_title'))
        ->assertSee('Sin horario uno')
        ->assertSee('Grace Hopper')
        ->assertSee('Sin horario dos')
        ->assertDontSee(__('program.agenda.pending_empty'));
});

it('CA-031-25: a failure reading the agenda shows an alert and reports the error instead of an empty agenda', function (): void {
    Exceptions::fake();
    fakeAgenda(fn (?string $date): AgendaDay => throw new RuntimeException('la base falló'));
    [$organization, $event, $admin] = formScenario();

    $component = agendaPage($organization, $admin, $event)
        ->assertSee(__('program.agenda.error_title'))
        ->assertSeeHtml('role="alert"')
        ->assertDontSee(__('program.agenda.empty_general_title'))
        ->assertDontSee(__('program.agenda.empty_day'));

    expect($component->html())->not->toContain('data-agenda-column=');
    Exceptions::assertReported(RuntimeException::class);
});

it('CA-031-30: the grid scrolls inside its own region with a sticky hour axis and keyboard access, and the buttons are touch sized', function (): void {
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay([new AgendaCell('act-1', 'Taller', 9 * 60, 10 * 60)]));
    [$organization, $event, $admin] = formScenario();

    $html = agendaPage($organization, $admin, $event)->html();

    expect($html)->toContain('agenda-scroll')
        ->and($html)->toMatch('/class="[^"]*agenda-scroll[^"]*"[^>]*tabindex="0"|tabindex="0"[^>]*class="[^"]*agenda-scroll/')
        ->and($html)->toContain('agenda-axis')
        ->and($html)->toContain('agenda-day-button');
});

it('CA-031-25: shows a loading indicator while the day changes', function (): void {
    fakeAgenda(fn (?string $date): AgendaDay => agendaDay());
    [$organization, $event, $admin] = formScenario();

    agendaPage($organization, $admin, $event)->assertSeeHtml('wire:loading')->assertSee(__('program.agenda.loading'));
});
