<?php

declare(strict_types=1);

use App\Enums\CallStatus;
use App\Enums\EventStatus;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\SubmissionType;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config(['app.locale' => 'es']);
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/*
 * Plan 030, F1: un evento archivado responde 410 con la página «ya no está disponible» en todas sus páginas
 * públicas; un borrador o lo inexistente sigue en 404; los medios siguen en 404.
 */

/** @return array{0: Event, 1: CallForSubmissions} evento en el estado dado con una convocatoria publicada */
function unavailableScenario(EventStatus $status, CallStatus $callStatus = CallStatus::Published): array
{
    $organization = Organization::factory()->create();

    $event = Event::factory()->create([
        'organization_id' => $organization->id,
        'name' => ['es' => 'Congreso Parques', 'en' => 'Parks Congress'],
        'description' => ['es' => 'Descripcion secreta del congreso', 'en' => 'Secret congress description'],
        'venue_name' => 'Auditorio Reservado',
        'slug' => 'congreso',
        'locales' => ['es', 'en'],
        'default_locale' => 'es',
        'status' => $status,
    ]);

    $type = SubmissionType::factory()->create([
        'organization_id' => $organization->id,
        'event_id' => $event->id,
        'name' => ['es' => 'Sesión', 'en' => 'Session'],
    ]);

    $call = CallForSubmissions::factory()->create([
        'organization_id' => $organization->id,
        'event_id' => $event->id,
        'submission_type_id' => $type->id,
        'slug' => 'ponencias',
        'status' => $callStatus,
        'title' => ['es' => 'Convocatoria Ponencias Reservada', 'en' => 'Reserved Call for Talks'],
        'locales' => ['es', 'en'],
        'opens_at' => '2027-01-10 15:00:00',
        'closes_at' => '2027-02-15 23:00:00',
    ]);

    return [$event, $call];
}

/** @return array<string, string> las páginas públicas con interfaz de un evento, por nombre */
function unavailablePages(Event $event): array
{
    $base = "/{$event->organization->slug}/events/{$event->slug}";

    return [
        'event' => $base,
        'call' => "{$base}/call/ponencias",
        'submission form' => "{$base}/call/ponencias/submit",
        'confirmation' => "{$base}/call/ponencias/submitted",
        'program' => "{$base}/program",
        'privacy notice' => "{$base}/privacy",
    ];
}

it('CA-030-8: an archived event answers 410 with the unavailable page on each of its public pages', function (string $page): void {
    [$event] = unavailableScenario(EventStatus::Archived);

    // Las peticiones de prueba traen `Accept-Language: en-us`: el visitante declara el suyo.
    $this->get(unavailablePages($event)[$page], ['Accept-Language' => 'es'])
        ->assertStatus(410)
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Events/Unavailable')
            ->where('event.name', 'Congreso Parques')
            ->where('organization.name', $event->organization->name)
            ->where('organization.slug', $event->organization->slug));
})->with(['event', 'call', 'submission form', 'confirmation', 'program', 'privacy notice']);

it('CA-030-9: the unavailable page carries the event name and the organization and nothing else of the event', function (string $page): void {
    [$event] = unavailableScenario(EventStatus::Archived);

    $response = $this->get(unavailablePages($event)[$page], ['Accept-Language' => 'es'])->assertStatus(410);

    $response->assertInertia(fn (Assert $inertia) => $inertia
        ->component('Events/Unavailable')
        ->where('event', ['name' => 'Congreso Parques'])
        ->where('organization', ['name' => $event->organization->name, 'slug' => $event->organization->slug])
        ->missing('landing')->missing('call')->missing('submitUrl')->missing('nav')->missing('privacyUrl'));

    foreach (['Descripcion secreta', 'Secret congress', 'Auditorio Reservado', 'Convocatoria Ponencias Reservada', 'Reserved Call'] as $secret) {
        expect($response->getContent())->not->toContain($secret);
    }
})->with(['event', 'call', 'submission form', 'confirmation', 'program', 'privacy notice']);

it('CA-030-10: a draft event keeps answering 404 on every public page and reveals nothing', function (string $page): void {
    [$event] = unavailableScenario(EventStatus::Draft);

    $response = $this->get(unavailablePages($event)[$page])->assertNotFound();

    foreach (['Events\/Unavailable', 'Events/Unavailable', 'Congreso Parques'] as $revealed) {
        expect($response->getContent())->not->toContain($revealed);
    }
})->with(['event', 'call', 'submission form', 'confirmation', 'program', 'privacy notice']);

it('CA-030-11: a missing event or call, or one of another organization, answers 404 and not 410', function (): void {
    [$event] = unavailableScenario(EventStatus::Archived);
    $other = Organization::factory()->create();
    $base = "/{$event->organization->slug}/events/{$event->slug}";

    $this->get("/{$event->organization->slug}/events/no-existe")->assertNotFound();
    $this->get("{$base}/call/no-existe")->assertNotFound();
    $this->get("{$base}/call/no-existe/submit")->assertNotFound();
    // El evento archivado de una organización pedido bajo otra: ni 410 ni pista de que existe.
    $this->get("/{$other->slug}/events/{$event->slug}")->assertNotFound();
    $this->get("/{$other->slug}/events/{$event->slug}/call/ponencias")->assertNotFound();
    $this->get("/{$other->slug}/events/{$event->slug}/program")->assertNotFound();
});

it('CA-030-12: sending a proposal to an archived event creates nothing and reveals nothing about the event', function (): void {
    [$event] = unavailableScenario(EventStatus::Archived);

    $response = $this->postJson("/{$event->organization->slug}/events/{$event->slug}/call/ponencias/submit", ['title' => 'Mi propuesta'])
        ->assertStatus(410)
        ->assertJson(['code' => 'EVENT_UNAVAILABLE']);

    expect(DB::table('submissions')->count())->toBe(0);

    foreach (['Congreso Parques', 'Auditorio Reservado'] as $revealed) {
        expect($response->getContent())->not->toContain($revealed);
    }
});

it('CA-030-12: a draft event still answers 404 to the submission', function (): void {
    [$event] = unavailableScenario(EventStatus::Draft);

    $this->postJson("/{$event->organization->slug}/events/{$event->slug}/call/ponencias/submit", ['title' => 'Mi propuesta'])->assertNotFound();

    expect(DB::table('submissions')->count())->toBe(0);
});

it('CA-030-13: the media routes of an archived event keep answering 404', function (): void {
    [$event] = unavailableScenario(EventStatus::Archived);
    $base = "/{$event->organization->slug}/events/{$event->slug}";
    $id = (string) Str::uuid();

    $this->get("{$base}/logo")->assertNotFound();
    $this->get("{$base}/speakers/{$id}/photo")->assertNotFound();
    $this->get("{$base}/activities/{$id}/icon")->assertNotFound();
    $this->get("{$base}/activities/{$id}/image")->assertNotFound();
    $this->get("{$base}/landing/images/{$id}")->assertNotFound();
});

it('CA-030-14: a paused or closed call of a published event shows as before, with its state', function (CallStatus $status, string $state): void {
    [$event] = unavailableScenario(EventStatus::Published, $status);

    $this->get("/{$event->organization->slug}/events/{$event->slug}/call/ponencias")
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia->component('Calls/Show')->where('call.state', $state));
})->with([
    'paused' => [CallStatus::Paused, 'paused'],
    'closed' => [CallStatus::Closed, 'closed'],
]);

it('CA-030-14: a published event keeps answering 200 on its pages', function (): void {
    [$event] = unavailableScenario(EventStatus::Published);

    $this->get("/{$event->organization->slug}/events/{$event->slug}")->assertOk()->assertInertia(fn (Assert $inertia) => $inertia->component('Events/Show'));
});

it('CA-030-15: the unavailable page uses ?lang=, then the browser language when it is es or en, then the system base language', function (string $query, string $header, string $locale, string $title): void {
    [$event] = unavailableScenario(EventStatus::Archived);

    $this->get("/{$event->organization->slug}/events/{$event->slug}{$query}", ['Accept-Language' => $header])
        ->assertStatus(410)
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Events/Unavailable')
            ->where('locale', $locale)
            ->where('translations.unavailable.title', $title));
})->with([
    '?lang=en wins over the browser' => ['?lang=en', 'es-MX,es;q=0.9', 'en', 'This event is no longer available'],
    '?lang=es wins over the browser' => ['?lang=es', 'en-US,en;q=0.9', 'es', 'Este evento ya no está disponible'],
    'browser in english' => ['', 'en-US,en;q=0.9', 'en', 'This event is no longer available'],
    'browser in spanish' => ['', 'es-MX,es;q=0.9', 'es', 'Este evento ya no está disponible'],
    'first supported language of the browser' => ['', 'fr-FR,en;q=0.8', 'en', 'This event is no longer available'],
    'unsupported browser language falls back to the base language' => ['', 'fr-FR,de;q=0.9', 'es', 'Este evento ya no está disponible'],
    'unsupported ?lang falls back to the browser' => ['?lang=pt', 'en-US', 'en', 'This event is no longer available'],
    'no preference at all falls back to the base language' => ['', '', 'es', 'Este evento ya no está disponible'],
]);

it('CA-030-16: the unavailable page is not indexed and not kept in shared caches', function (): void {
    [$event] = unavailableScenario(EventStatus::Archived);

    $response = $this->get("/{$event->organization->slug}/events/{$event->slug}")->assertStatus(410);

    expect($response->headers->get('X-Robots-Tag'))->toBe('noindex')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});
