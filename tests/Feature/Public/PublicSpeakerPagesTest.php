<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\OrganizationStatus;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\Speaker;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
 * Plan 029, F3: páginas públicas de ponentes (`speakers`, `speakers/{speaker}`) y detalle de actividad
 * (`activities/{activity}`): ruta, controlador delgado y props de §4.3. La consulta y los constructores
 * se prueban en F1; aquí, la visibilidad vista desde la ruta, el 404 uniforme, el idioma y la lista cerrada.
 */

function spkBase(Event $event): string
{
    return "/{$event->organization->slug}/events/{$event->slug}";
}

/** @param  array<string, mixed>  $attributes */
function spkEvent(array $attributes = []): Event
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

/** @return array<string, string> las tres rutas con un identificador válido que no existe */
function spkRoutes(Event $event): array
{
    $uuid = (string) Str::uuid();

    return [
        'speakers' => spkBase($event).'/speakers',
        'speaker' => spkBase($event)."/speakers/{$uuid}",
        'activity' => spkBase($event)."/activities/{$uuid}",
    ];
}

// --- Lista de ponentes -----------------------------------------------------------------------------

it('CA-029-8: lists the visible speakers ordered by last name and first name without regard to case', function (): void {
    $event = spkEvent();
    ppSpeaker($event, ['first_name' => 'Zoe', 'last_name' => 'Zeta']);
    ppSpeaker($event, ['first_name' => 'Ana', 'last_name' => 'alfa']);
    ppSpeaker($event, ['first_name' => 'Luis', 'last_name' => 'Álvarez']);
    ppSpeaker($event, ['first_name' => 'Oculto', 'last_name' => 'Aaa', 'is_published' => false]);
    ppSpeaker($event, ['first_name' => 'Reversa', 'last_name' => 'Aab', 'hidden_by_reversal_at' => now()]);
    ppSpeaker($event, ['first_name' => 'Borrado', 'last_name' => 'Aac', 'deleted_at' => now()]);

    // Las peticiones de prueba traen `Accept-Language: en-us`: el visitante declara el suyo.
    $this->get(spkBase($event).'/speakers', ['Accept-Language' => 'es-MX,es;q=0.9'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Events/Speakers')
            ->has('speakers.data', 3)
            ->where('speakers.data.0.name', 'Ana alfa')
            ->where('speakers.data.1.name', 'Luis Álvarez')
            ->where('speakers.data.2.name', 'Zoe Zeta')
            ->where('speakers.currentPage', 1)
            ->where('speakers.lastPage', 1)
            ->where('speakers.prevUrl', null)
            ->where('speakers.nextUrl', null)
            ->where('event.name', 'Congreso Parques')
            ->where('organization.slug', $event->organization->slug));
});

it('CA-029-8: pages the list in 48 by default with relative links that keep the language', function (): void {
    $event = spkEvent();
    $base = spkBase($event).'/speakers';

    foreach (range(1, 49) as $number) {
        ppSpeaker($event, ['first_name' => 'Nombre', 'last_name' => sprintf('Apellido%02d', $number)]);
    }

    $this->get("{$base}?lang=en")
        ->assertInertia(fn (Assert $page) => $page
            ->has('speakers.data', 48)
            ->where('speakers.currentPage', 1)
            ->where('speakers.lastPage', 2)
            ->where('speakers.prevUrl', null)
            ->where('speakers.nextUrl', "{$base}?lang=en&page=2"));

    $this->get("{$base}?page=2")
        ->assertInertia(fn (Assert $page) => $page
            ->has('speakers.data', 1)
            ->where('speakers.data.0.name', 'Nombre Apellido49')
            ->where('speakers.currentPage', 2)
            ->where('speakers.prevUrl', $base)
            ->where('speakers.nextUrl', null));
});

it('CA-029-8: an invalid page number is read as the first page without error', function (string $query): void {
    $event = spkEvent();
    ppSpeaker($event, ['first_name' => 'Ana', 'last_name' => 'Uno']);

    $this->get(spkBase($event).'/speakers'.$query)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('speakers.currentPage', 1)->has('speakers.data', 1));
})->with([
    'text' => ['?page=abc'],
    'zero' => ['?page=0'],
    'negative' => ['?page=-3'],
    'array' => ['?page[]=2'],
]);

it('CA-029-11: a published speaker without visible activities appears in the list', function (): void {
    $event = spkEvent();
    ppSpeaker($event, ['first_name' => 'Invitada', 'last_name' => 'Sinactividad']);

    $this->get(spkBase($event).'/speakers')
        ->assertInertia(fn (Assert $page) => $page->has('speakers.data', 1)->where('speakers.data.0.name', 'Invitada Sinactividad'));
});

it('CA-029-17: a published event without visible speakers answers 200 with an empty list', function (): void {
    $event = spkEvent();
    ppSpeaker($event, ['is_published' => false]);

    $this->get(spkBase($event).'/speakers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Events/Speakers')
            ->has('speakers.data', 0)
            ->where('speakers.lastPage', 1));
});

it('CA-029-5: the list never shows speakers of another organization with the same event slug', function (): void {
    $mine = spkEvent(['slug' => 'congreso']);
    $other = spkEvent(['slug' => 'congreso']);
    ppSpeaker($mine, ['first_name' => 'Mia', 'last_name' => 'Propia']);
    ppSpeaker($other, ['first_name' => 'Ajena', 'last_name' => 'Propia']);

    $this->get(spkBase($mine).'/speakers')
        ->assertInertia(fn (Assert $page) => $page->has('speakers.data', 1)->where('speakers.data.0.name', 'Mia Propia'));
});

it('CA-029-1: the speakers page sends the program and speakers links of the navigation', function (): void {
    $event = spkEvent();
    $slug = $event->organization->slug;
    ppSpeaker($event);

    $this->get(spkBase($event).'/speakers?lang=en')
        ->assertInertia(fn (Assert $page) => $page
            ->where('nav.programUrl', "/{$slug}/events/{$event->slug}/program?lang=en")
            ->where('nav.speakersUrl', "/{$slug}/events/{$event->slug}/speakers?lang=en")
            ->where('nav.eventUrl', "/{$slug}/events/{$event->slug}?lang=en")
            ->where('privacyUrl', null));
});

it('CA-029-1: the program page now links to the speakers page', function (): void {
    $event = spkEvent();
    ppActivity($event);

    $this->get(spkBase($event).'/program')
        ->assertInertia(fn (Assert $page) => $page->where('nav.speakersUrl', spkBase($event).'/speakers'));
});

// --- Ficha de ponente --------------------------------------------------------------------------------

it('CA-029-9: the profile shows the speaker data, the sanitized bio, the https networks and only the visible activities in order', function (): void {
    $event = spkEvent();
    $speaker = ppSpeaker($event, [
        'first_name' => 'Ada', 'last_name' => 'Lovelace', 'company' => 'Analytical SA', 'job_title' => 'Matematica',
        'tagline' => 'Pionera', 'bio' => '<p>Biografia</p>', 'photo_path' => 'organizations/x/foto.png',
        'social_links' => [['network' => 'web', 'url' => 'https://example.test/ada']],
    ]);
    ppIn($event, fn () => $speaker->categories()->attach(ppSpeakerCategory($event, 'Invitados')->id, ['organization_id' => $event->organization_id, 'event_id' => $event->id]));
    $second = ppActivity($event, ['title' => 'Tarde', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10', 'starts_at' => '15:00:00', 'ends_at' => '16:00:00']);
    $first = ppActivity($event, ['title' => 'Manana', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10', 'starts_at' => '09:00:00', 'ends_at' => '10:00:00']);
    $hidden = ppActivity($event, ['title' => 'Despublicada', 'is_published' => false]);
    $unscheduled = ppActivity($event, ['title' => 'Sin programar', 'starts_on' => null, 'ends_on' => null, 'starts_at' => null, 'ends_at' => null]);
    ppAssign($second, [$speaker]);
    ppAssign($first, [$speaker]);
    ppAssign($hidden, [$speaker]);
    ppAssign($unscheduled, [$speaker]);

    $this->get(spkBase($event)."/speakers/{$speaker->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Events/Speaker')
            ->where('speaker.name', 'Ada Lovelace')
            ->where('speaker.company', 'Analytical SA')
            ->where('speaker.jobTitle', 'Matematica')
            ->where('speaker.tagline', 'Pionera')
            ->where('speaker.bioHtml', '<p>Biografia</p>')
            ->where('speaker.photoUrl', route('public.speakers.photo', ['organization' => $event->organization->slug, 'event' => $event->slug, 'speaker' => $speaker->id], false))
            ->where('speaker.categories', ['Invitados'])
            ->where('speaker.socialLinks', [['network' => 'web', 'url' => 'https://example.test/ada']])
            ->has('activities', 2)
            ->where('activities.0.title', 'Manana')
            ->where('activities.1.title', 'Tarde')
            ->where('nav.speakersUrl', spkBase($event).'/speakers')
            ->where('nav.programUrl', spkBase($event).'/program'));
});

it('CA-029-11: a speaker without visible activities gets an empty activity list on the profile', function (): void {
    $event = spkEvent();
    $speaker = ppSpeaker($event);

    $this->get(spkBase($event)."/speakers/{$speaker->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('activities', 0));
});

it('CA-029-9: answers 404 for a speaker that is not visible, from another event or whose id is not a uuid', function (): void {
    $event = spkEvent();
    $unpublished = ppSpeaker($event, ['is_published' => false]);
    $reversed = ppSpeaker($event, ['hidden_by_reversal_at' => now()]);
    $deleted = ppSpeaker($event, ['deleted_at' => now()]);
    $sibling = Event::factory()->published()->create(['organization_id' => $event->organization_id]);
    $ofSibling = ppSpeaker($sibling);

    foreach ([$unpublished, $reversed, $deleted, $ofSibling] as $speaker) {
        $this->get(spkBase($event)."/speakers/{$speaker->id}")->assertNotFound();
    }

    $this->get(spkBase($event).'/speakers/no-es-uuid')->assertNotFound();
});

it('CA-029-5: a speaker of another organization answers 404', function (): void {
    $mine = spkEvent(['slug' => 'congreso']);
    $other = spkEvent(['slug' => 'congreso']);
    $foreign = ppSpeaker($other);

    $this->get(spkBase($mine)."/speakers/{$foreign->id}")->assertNotFound();
});

it('CA-029-2: the profile of a speaker does not list activities that are hidden, deleted or from another event', function (): void {
    $event = spkEvent();
    $speaker = ppSpeaker($event);
    $visible = ppActivity($event, ['title' => 'Visible']);
    $reversed = ppActivity($event, ['title' => 'Reversa', 'hidden_by_reversal_at' => now()]);
    $deleted = ppActivity($event, ['title' => 'Borrada', 'deleted_at' => now()]);
    ppAssign($visible, [$speaker]);
    ppAssign($reversed, [$speaker]);
    ppAssign($deleted, [$speaker]);

    $this->get(spkBase($event)."/speakers/{$speaker->id}")
        ->assertInertia(fn (Assert $page) => $page->has('activities', 1)->where('activities.0.title', 'Visible'));
});

it('CA-029-3: the profile of a speaker does not list an activity without date', function (): void {
    $event = spkEvent();
    $speaker = ppSpeaker($event);
    $unscheduled = ppActivity($event, ['starts_on' => null, 'ends_on' => null, 'starts_at' => null, 'ends_at' => null]);
    ppAssign($unscheduled, [$speaker]);

    $this->get(spkBase($event)."/speakers/{$speaker->id}")
        ->assertInertia(fn (Assert $page) => $page->has('activities', 0));
});

it('CA-029-16: the profile never sends a script, a javascript link nor a network that is not https', function (): void {
    $event = spkEvent();
    $speaker = ppSpeaker($event, [
        'bio' => '<p>Hola</p><script>alert(1)</script><a href="javascript:alert(2)">clic</a>',
        'social_links' => [
            ['network' => 'web', 'url' => 'https://seguro.example.test/perfil'],
            ['network' => 'viejo', 'url' => 'http://inseguro.example.test'],
            ['network' => 'js', 'url' => 'javascript:alert(3)'],
        ],
    ]);

    $response = $this->get(spkBase($event)."/speakers/{$speaker->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('speaker.socialLinks', [['network' => 'web', 'url' => 'https://seguro.example.test/perfil']]));

    $props = json_encode($response->viewData('page')['props']['speaker'], JSON_THROW_ON_ERROR);

    expect($props)->not->toContain('<script')
        ->and($props)->not->toContain('javascript:')
        ->and($props)->not->toContain('alert(');
});

it('CA-029-7: no page sends the email nor the country of a speaker', function (): void {
    $event = spkEvent();
    $speaker = ppSpeaker($event, ['email' => 'secreto.ponente@example.test', 'country' => 'NR']);
    $activity = ppActivity($event);
    ppAssign($activity, [$speaker]);

    foreach (['/speakers', "/speakers/{$speaker->id}", "/activities/{$activity->id}"] as $path) {
        $body = $this->get(spkBase($event).$path)->assertOk()->getContent();

        expect($body)->not->toContain('secreto.ponente')
            ->and($body)->not->toContain('&quot;NR&quot;')
            ->and($body)->not->toContain('&quot;email&quot;')
            ->and($body)->not->toContain('&quot;country&quot;');
    }
});

// --- Detalle de actividad ----------------------------------------------------------------------------

it('CA-029-10: the activity detail shows its visible speakers in the order of position and keeps the activity visible', function (): void {
    $event = spkEvent();
    $activity = ppActivity($event, ['title' => 'Mesa', 'summary' => 'Resumen', 'description' => '<p>Descripcion</p>', 'image_path' => 'organizations/x/imagen.png']);
    $first = ppSpeaker($event, ['first_name' => 'Primero', 'last_name' => 'Zeta']);
    $hiddenSpeaker = ppSpeaker($event, ['first_name' => 'Oculto', 'last_name' => 'Medio', 'is_published' => false]);
    $third = ppSpeaker($event, ['first_name' => 'Tercero', 'last_name' => 'Alfa']);
    ppAssign($activity, [$first, $hiddenSpeaker, $third]);
    ppTag($activity, ppCategory($event, 'Mesas'));

    $this->get(spkBase($event)."/activities/{$activity->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Events/Activity')
            ->where('activity.title', 'Mesa')
            ->where('activity.summary', 'Resumen')
            ->where('activity.descriptionHtml', '<p>Descripcion</p>')
            ->where('activity.imageUrl', route('public.activities.media', ['organization' => $event->organization->slug, 'event' => $event->slug, 'activity' => $activity->id, 'type' => 'image'], false))
            ->where('activity.categories.0.name', 'Mesas')
            ->has('activity.speakers', 2)
            ->where('activity.speakers.0.name', 'Primero Zeta')
            ->where('activity.speakers.1.name', 'Tercero Alfa')
            ->where('activity.speakers.0.url', spkBase($event)."/speakers/{$first->id}")
            ->where('nav.programUrl', spkBase($event).'/program')
            ->where('nav.speakersUrl', spkBase($event).'/speakers'));
});

it('CA-029-2: answers 404 for an activity that is unpublished, hidden by a reversal, deleted or from another event', function (): void {
    $event = spkEvent();
    $unpublished = ppActivity($event, ['is_published' => false]);
    $reversed = ppActivity($event, ['hidden_by_reversal_at' => now()]);
    $deleted = ppActivity($event, ['deleted_at' => now()]);
    $sibling = Event::factory()->published()->create(['organization_id' => $event->organization_id]);
    $ofSibling = ppActivity($sibling);

    foreach ([$unpublished, $reversed, $deleted, $ofSibling] as $activity) {
        $this->get(spkBase($event)."/activities/{$activity->id}")->assertNotFound();
    }

    $this->get(spkBase($event).'/activities/no-es-uuid')->assertNotFound();
});

it('CA-029-3: answers 404 for an activity without date', function (): void {
    $event = spkEvent();
    $unscheduled = ppActivity($event, ['starts_on' => null, 'ends_on' => null, 'starts_at' => null, 'ends_at' => null]);

    $this->get(spkBase($event)."/activities/{$unscheduled->id}")->assertNotFound();
});

it('CA-029-5: an activity of another organization answers 404', function (): void {
    $mine = spkEvent(['slug' => 'congreso']);
    $other = spkEvent(['slug' => 'congreso']);
    $foreign = ppActivity($other);

    $this->get(spkBase($mine)."/activities/{$foreign->id}")->assertNotFound();
});

it('CA-029-16: the activity detail never sends a script or a javascript link in its description', function (): void {
    $event = spkEvent();
    $activity = ppActivity($event, ['description' => '<p>Texto</p><script>alert(1)</script><a href="javascript:alert(2)">clic</a>']);

    $response = $this->get(spkBase($event)."/activities/{$activity->id}")->assertOk();
    $props = json_encode($response->viewData('page')['props']['activity'], JSON_THROW_ON_ERROR);

    expect($props)->not->toContain('<script')->and($props)->not->toContain('javascript:');
});

it('CA-029-15: the interface of the detail pages follows ?lang= and the texts of the activity stay as saved', function (): void {
    $event = spkEvent();
    $speaker = ppSpeaker($event);
    $activity = ppActivity($event, ['title' => 'Texto tal como se guardo']);
    ppAssign($activity, [$speaker]);

    $this->get(spkBase($event)."/activities/{$activity->id}?lang=en")
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'en')
            ->where('event.name', 'Parks Congress')
            ->where('activity.title', 'Texto tal como se guardo')
            ->where('nav.programUrl', spkBase($event).'/program?lang=en'));

    $this->get(spkBase($event)."/speakers/{$speaker->id}?lang=en")
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en')->where('nav.speakersUrl', spkBase($event).'/speakers?lang=en'));
});

// --- Las tres rutas: 404 uniforme --------------------------------------------------------------------

it('CA-029-4: answers 404 on the three routes for a draft, archived, nonexistent or other-organization event and a suspended organization', function (): void {
    $draft = spkEvent(['status' => EventStatus::Draft]);
    $archived = spkEvent(['status' => EventStatus::Archived]);
    $own = spkEvent();
    $other = spkEvent();
    $suspended = Organization::factory()->create(['status' => OrganizationStatus::Suspended]);
    $suspendedEvent = Event::factory()->published()->create(['organization_id' => $suspended->id]);

    foreach (['/speakers', '/speakers/'.Str::uuid(), '/activities/'.Str::uuid()] as $tail) {
        $this->get(spkBase($draft).$tail)->assertNotFound();
        $this->get(spkBase($archived).$tail)->assertNotFound();
        $this->get(spkBase($suspendedEvent).$tail)->assertNotFound();
        $this->get("/{$own->organization->slug}/events/no-existe{$tail}")->assertNotFound();
        $this->get("/{$own->organization->slug}/events/{$other->slug}{$tail}")->assertNotFound();
        $this->get("/no-existe/events/{$own->slug}{$tail}")->assertNotFound();
    }
});

it('CA-029-4: the 404 of a draft event is the same response as the one of an unknown event on the three routes', function (): void {
    $draft = spkEvent(['status' => EventStatus::Draft]);

    foreach (['/speakers', '/speakers/'.Str::uuid(), '/activities/'.Str::uuid()] as $tail) {
        $known = $this->get(spkBase($draft).$tail);
        $unknown = $this->get("/{$draft->organization->slug}/events/no-existe{$tail}");

        expect($known->getStatusCode())->toBe($unknown->getStatusCode())
            ->and($known->headers->get('Content-Type'))->toBe($unknown->headers->get('Content-Type'));
    }
});

it('CA-029-4: a missing speaker or activity of a published event answers the same 404 as an unpublished one', function (): void {
    $event = spkEvent();
    $unpublished = ppSpeaker($event, ['is_published' => false]);

    $absent = $this->get(spkBase($event).'/speakers/'.Str::uuid());
    $hidden = $this->get(spkBase($event)."/speakers/{$unpublished->id}");

    expect($absent->getStatusCode())->toBe($hidden->getStatusCode())
        ->and($absent->headers->get('Content-Type'))->toBe($hidden->headers->get('Content-Type'));
});

it('CA-029-4: the routes of the program do not collide with the ones of the images', function (): void {
    $event = spkEvent();
    $speaker = ppSpeaker($event, ['photo_path' => null]);
    $activity = ppActivity($event);

    // Las rutas de imagen siguen resolviendo a su controlador (404 por no tener archivo), no a las páginas.
    $this->get(spkBase($event)."/speakers/{$speaker->id}/photo")->assertNotFound();
    $this->get(spkBase($event)."/activities/{$activity->id}/icon")->assertNotFound();
    $this->get(spkBase($event)."/activities/{$activity->id}")->assertOk();
});

it('CA-029-2: a despublication is visible on the very next request on the three pages', function (): void {
    $event = spkEvent();
    $speaker = ppSpeaker($event);
    $activity = ppActivity($event);
    ppAssign($activity, [$speaker]);

    $this->get(spkBase($event)."/activities/{$activity->id}")->assertOk();
    $this->get(spkBase($event)."/speakers/{$speaker->id}")->assertOk();

    ppIn($event, function () use ($speaker, $activity): void {
        EventActivity::query()->whereKey($activity->id)->update(['is_published' => false]);
        Speaker::query()->whereKey($speaker->id)->update(['is_published' => false]);
    });

    $this->get(spkBase($event)."/activities/{$activity->id}")->assertNotFound();
    $this->get(spkBase($event)."/speakers/{$speaker->id}")->assertNotFound();
    $this->get(spkBase($event).'/speakers')->assertInertia(fn (Assert $page) => $page->has('speakers.data', 0));
});

it('CA-029-1: the urls built by the data builders are the ones of the named routes', function (): void {
    $event = spkEvent();
    $speaker = ppSpeaker($event);
    $activity = ppActivity($event);
    ppAssign($activity, [$speaker]);

    $slug = $event->organization->slug;
    $speakerUrl = route('events.speakers.show', ['organization' => $slug, 'event' => $event->slug, 'speaker' => $speaker->id], false);
    $activityUrl = route('events.activities.show', ['organization' => $slug, 'event' => $event->slug, 'activity' => $activity->id], false);

    $this->get(spkBase($event).'/program')
        ->assertInertia(fn (Assert $page) => $page
            ->where('activities.data.0.url', $activityUrl)
            ->where('activities.data.0.speakers.0.url', $speakerUrl));

    $this->get(spkBase($event).'/speakers')
        ->assertInertia(fn (Assert $page) => $page->where('speakers.data.0.url', $speakerUrl));
});
