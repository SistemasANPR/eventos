<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\OrganizationStatus;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/public-program-helpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/*
 * Plan 029, F2: página pública `events/{evento}/program` (ruta, controlador delgado y props de §4.3).
 * La consulta y los constructores de datos ya se prueban en F1; aquí, la ruta, el idioma, el día
 * elegido, la paginación y el 404 uniforme.
 */

function programPageUrl(Event $event, string $query = ''): string
{
    return "/{$event->organization->slug}/events/{$event->slug}/program".$query;
}

/** @param  array<string, mixed>  $attributes */
function programPageEvent(array $attributes = []): Event
{
    $organization = Organization::factory()->create();

    return Event::factory()->published()->create([
        'organization_id' => $organization->id,
        'name' => ['es' => 'Congreso Parques', 'en' => 'Parks Congress'],
        'locales' => ['es', 'en'],
        'default_locale' => 'es',
        'timezone' => 'America/Mexico_City',
    ] + $attributes);
}

it('CA-029-1: answers 200 with the days in ascending order and only the activities of the first day', function (): void {
    $event = programPageEvent();
    ppActivity($event, ['title' => 'Cierre', 'starts_on' => '2027-03-12', 'ends_on' => '2027-03-12']);
    ppActivity($event, ['title' => 'Tarde', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10', 'starts_at' => '15:00:00', 'ends_at' => '16:00:00']);
    ppActivity($event, ['title' => 'Apertura', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10', 'starts_at' => '09:00:00', 'ends_at' => '10:00:00']);

    // Las peticiones de prueba traen `Accept-Language: en-us`: el visitante declara el suyo.
    $this->get(programPageUrl($event), ['Accept-Language' => 'es-MX,es;q=0.9'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Events/Program')
            ->where('days', ['2027-03-10', '2027-03-12'])
            ->where('day', '2027-03-10')
            ->has('activities.data', 2)
            ->where('activities.data.0.title', 'Apertura')
            ->where('activities.data.1.title', 'Tarde')
            ->where('activities.currentPage', 1)
            ->where('activities.lastPage', 1)
            ->where('activities.prevUrl', null)
            ->where('activities.nextUrl', null)
            ->where('event.name', 'Congreso Parques')
            ->where('event.timezone', 'America/Mexico_City')
            ->where('organization.slug', $event->organization->slug));
});

it('CA-029-1: sends the public props of section 4.3 with relative urls and the event navigation', function (): void {
    $event = programPageEvent();
    $slug = $event->organization->slug;
    ppActivity($event, ['title' => 'Apertura']);

    $this->get(programPageUrl($event))
        ->assertInertia(fn (Assert $page) => $page
            ->where('nav.programUrl', "/{$slug}/events/{$event->slug}/program")
            ->where('nav.eventUrl', "/{$slug}/events/{$event->slug}")
            ->where('privacyUrl', null)
            ->has('categories', 0)
            ->has('activities.data.0', fn (Assert $activity) => $activity
                ->hasAll(['id', 'title', 'summary', 'date', 'endDate', 'startTime', 'endTime', 'categories', 'speakers', 'iconUrl', 'url'])));
});

it('CA-029-1: sends only the categories used by the shown day', function (): void {
    $event = programPageEvent();
    $used = ppCategory($event, 'Talleres', 1);
    ppCategory($event, 'Sin uso', 2);
    ppTag(ppActivity($event, ['title' => 'Uno']), $used);

    $this->get(programPageUrl($event))
        ->assertInertia(fn (Assert $page) => $page
            ->has('categories', 1)
            ->where('categories.0.name', 'Talleres'));
});

it('CA-029-1: paginates a day of more than one page with relative links that keep the day and the language', function (): void {
    $event = programPageEvent();
    $base = "/{$event->organization->slug}/events/{$event->slug}/program";

    foreach (range(0, 48) as $minute) {
        ppActivity($event, ['title' => sprintf('Actividad %02d', $minute), 'starts_at' => sprintf('09:%02d:00', $minute), 'ends_at' => sprintf('10:%02d:00', $minute)]);
    }

    $this->get(programPageUrl($event, '?lang=en'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('activities.data', 48)
            ->where('activities.currentPage', 1)
            ->where('activities.lastPage', 2)
            ->where('activities.prevUrl', null)
            ->where('activities.nextUrl', "{$base}?lang=en&day=2027-03-10&page=2"));

    $this->get(programPageUrl($event, '?day=2027-03-10&page=2'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('activities.data', 1)
            ->where('activities.currentPage', 2)
            ->where('activities.prevUrl', "{$base}?day=2027-03-10")
            ->where('activities.nextUrl', null));
});

it('CA-029-14: shows the requested day when it has activities', function (): void {
    $event = programPageEvent();
    ppActivity($event, ['title' => 'Uno', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10']);
    ppActivity($event, ['title' => 'Dos', 'starts_on' => '2027-03-11', 'ends_on' => '2027-03-11']);

    $this->get(programPageUrl($event, '?day=2027-03-11'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('day', '2027-03-11')
            ->where('activities.data.0.title', 'Dos'));
});

it('CA-029-14: falls back to the first day without error for a day with no activities or a value that is not a date', function (string $query): void {
    $event = programPageEvent();
    ppActivity($event, ['title' => 'Uno', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10']);
    ppActivity($event, ['title' => 'Dos', 'starts_on' => '2027-03-12', 'ends_on' => '2027-03-12']);

    $this->get(programPageUrl($event, $query))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('day', '2027-03-10')
            ->where('activities.data.0.title', 'Uno'));
})->with([
    'day without activities' => ['?day=2027-03-11'],
    'text' => ['?day=manana'],
    'array' => ['?day[]=2027-03-12'],
    'impossible date' => ['?day=2027-13-45'],
]);

it('CA-029-14: an invalid page number is read as the first page without error', function (string $query): void {
    $event = programPageEvent();
    ppActivity($event, ['title' => 'Uno']);

    $this->get(programPageUrl($event, $query))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activities.currentPage', 1)
            ->where('activities.data.0.title', 'Uno'));
})->with([
    'text' => ['?page=abc'],
    'zero' => ['?page=0'],
    'negative' => ['?page=-3'],
    'array' => ['?page[]=2'],
]);

it('CA-029-12: sends the local date and time of the event without converting them', function (): void {
    $event = programPageEvent(['timezone' => 'America/Mexico_City', 'time_format' => '12h']);
    ppActivity($event, ['title' => 'Noche', 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-20', 'starts_at' => '23:30:00', 'ends_at' => '23:59:00']);

    $this->get(programPageUrl($event))
        ->assertInertia(fn (Assert $page) => $page
            ->where('day', '2026-11-20')
            ->where('activities.data.0.date', '2026-11-20')
            ->where('activities.data.0.startTime', '23:30')
            ->where('activities.data.0.endTime', '23:59')
            ->where('event.timeFormat', '12h'));
});

it('CA-029-12: tells the client the time format of the event', function (): void {
    $event = programPageEvent(['time_format' => '24h']);
    ppActivity($event);

    $this->get(programPageUrl($event))
        ->assertInertia(fn (Assert $page) => $page->where('event.timeFormat', '24h'));
});

it('CA-029-13: an activity that crosses midnight appears only under its start day and carries its end date', function (): void {
    $event = programPageEvent();
    ppActivity($event, ['title' => 'Noche', 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-21', 'starts_at' => '22:00:00', 'ends_at' => '02:00:00']);
    ppActivity($event, ['title' => 'Siguiente', 'starts_on' => '2026-11-21', 'ends_on' => '2026-11-21']);

    $this->get(programPageUrl($event, '?day=2026-11-20'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('days', ['2026-11-20', '2026-11-21'])
            ->has('activities.data', 1)
            ->where('activities.data.0.title', 'Noche')
            ->where('activities.data.0.date', '2026-11-20')
            ->where('activities.data.0.endDate', '2026-11-21')
            ->where('activities.data.0.endTime', '02:00'));

    $this->get(programPageUrl($event, '?day=2026-11-21'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('activities.data', 1)
            ->where('activities.data.0.title', 'Siguiente'));
});

it('CA-029-15: the interface follows ?lang=, falls back to the default language when it is not offered and follows Accept-Language', function (): void {
    $event = programPageEvent();
    ppActivity($event, ['title' => 'Texto tal como se guardo']);

    $this->get(programPageUrl($event, '?lang=en'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('event.locale', 'en')
            ->where('event.name', 'Parks Congress')
            ->where('locale', 'en')
            ->where('translations.program.title', 'Program')
            ->where('activities.data.0.title', 'Texto tal como se guardo'));

    // Un idioma no ofrecido se ignora; sin otra preferencia que el evento ofrezca, gana el idioma por defecto del evento.
    $this->get(programPageUrl($event, '?lang=pt'), ['Accept-Language' => 'fr'])
        ->assertInertia(fn (Assert $page) => $page
            ->where('event.locale', 'es')
            ->where('locale', 'es')
            ->where('translations.program.title', 'Programa'));

    $this->get(programPageUrl($event), ['Accept-Language' => 'en-US,en;q=0.9'])
        ->assertInertia(fn (Assert $page) => $page->where('event.locale', 'en'));

    $this->get(programPageUrl($event), ['Accept-Language' => 'fr'])
        ->assertInertia(fn (Assert $page) => $page->where('event.locale', 'es'));
});

it('CA-029-15: keeps the language asked for in the links of the page', function (): void {
    $event = programPageEvent();
    ppActivity($event);

    $this->get(programPageUrl($event, '?lang=en'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('nav.programUrl', "/{$event->organization->slug}/events/{$event->slug}/program?lang=en")
            ->where('nav.eventUrl', "/{$event->organization->slug}/events/{$event->slug}?lang=en"));
});

it('CA-029-17: a published event with nothing visible answers 200 with empty days and no activities', function (): void {
    $event = programPageEvent();
    ppActivity($event, ['is_published' => false]);
    ppActivity($event, ['starts_on' => null, 'ends_on' => null, 'starts_at' => null, 'ends_at' => null]);

    $this->get(programPageUrl($event))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Events/Program')
            ->where('days', [])
            ->where('day', null)
            ->has('activities.data', 0)
            ->has('categories', 0));
});

it('CA-029-4: answers 404 for a draft, nonexistent or other-organization event and for an unknown or suspended organization, and 410 for an archived one (plan 032)', function (): void {
    $draft = programPageEvent(['status' => EventStatus::Draft]);
    $archived = programPageEvent(['status' => EventStatus::Archived]);
    $own = programPageEvent();
    $other = programPageEvent();
    $suspended = Organization::factory()->create(['status' => OrganizationStatus::Suspended]);
    $suspendedEvent = Event::factory()->published()->create(['organization_id' => $suspended->id]);

    $this->get(programPageUrl($draft))->assertNotFound();
    $this->get(programPageUrl($archived))->assertStatus(410);
    $this->get("/{$own->organization->slug}/events/no-existe/program")->assertNotFound();
    $this->get("/{$own->organization->slug}/events/{$other->slug}/program")->assertNotFound();
    $this->get("/no-existe/events/{$own->slug}/program")->assertNotFound();
    $this->get(programPageUrl($suspendedEvent))->assertNotFound();
});

it('CA-029-4: the 404 of a draft event is the same response as the one of an unknown event', function (): void {
    $draft = programPageEvent(['status' => EventStatus::Draft]);

    $known = $this->get(programPageUrl($draft));
    $unknown = $this->get("/{$draft->organization->slug}/events/no-existe/program");

    expect($known->getStatusCode())->toBe($unknown->getStatusCode())
        ->and($known->headers->get('Content-Type'))->toBe($unknown->headers->get('Content-Type'));
});

it('CA-029-1: never shows activities of another organization with the same event slug', function (): void {
    $mine = programPageEvent(['slug' => 'congreso']);
    $other = programPageEvent(['slug' => 'congreso']);
    ppActivity($mine, ['title' => 'Mi actividad']);
    ppActivity($other, ['title' => 'Ajena']);

    $this->get(programPageUrl($mine))
        ->assertInertia(fn (Assert $page) => $page
            ->has('activities.data', 1)
            ->where('activities.data.0.title', 'Mi actividad'));
});
