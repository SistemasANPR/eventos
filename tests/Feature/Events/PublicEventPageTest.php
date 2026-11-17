<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @param array<string, mixed> $attributes */
function publishedEvent(?Organization $organization = null, array $attributes = []): Event
{
    $organization ??= Organization::factory()->create();

    return Event::factory()->published()->create([
        'organization_id' => $organization->id,
        'name' => ['es' => 'Congreso Parques', 'en' => 'Parks Congress'],
        'description' => ['es' => 'Descripción en español', 'en' => 'English description'],
        'slug' => 'congreso',
        'timezone' => 'America/Mexico_City',
        'venue_name' => 'Centro de Convenciones',
        'city' => 'Ciudad de México',
        'locales' => ['es', 'en'],
        'default_locale' => 'es',
        'starts_at' => '2027-03-10 15:00:00',
        'ends_at' => '2027-03-12 23:00:00',
    ] + $attributes);
}

it('CA-002-13: a visitor without session sees the published event', function (): void {
    $event = publishedEvent();
    $organization = $event->organization;

    // Las peticiones de prueba traen `Accept-Language: en-us` por defecto: el visitante declara el suyo.
    $this->get("/{$organization->slug}/events/{$event->slug}", ['Accept-Language' => 'es-MX,es;q=0.9'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Events/Show')
            ->where('event.name', 'Congreso Parques')
            ->where('event.description', 'Descripción en español')
            ->where('event.startsAt', '2027-03-10T15:00:00Z')
            ->where('event.endsAt', '2027-03-12T23:00:00Z')
            ->where('event.timezone', 'America/Mexico_City')
            ->where('event.venueName', 'Centro de Convenciones')
            ->where('event.city', 'Ciudad de México')
            ->where('event.locale', 'es'));
});

it('CA-002-13: shows the text in the language of the visitor when the event has it', function (): void {
    $event = publishedEvent();

    $this->get("/{$event->organization->slug}/events/{$event->slug}", ['Accept-Language' => 'en'])
        ->assertInertia(fn (Assert $page) => $page
            ->where('event.name', 'Parks Congress')
            ->where('event.locale', 'en'));
});

it('CA-002-13: the lang parameter picks one of the event languages', function (): void {
    $event = publishedEvent();

    $this->get("/{$event->organization->slug}/events/{$event->slug}?lang=en")
        ->assertInertia(fn (Assert $page) => $page->where('event.locale', 'en'));
});

it('CA-002-13: falls back to the default language when the visitor language is not offered', function (): void {
    $event = publishedEvent();

    $this->get("/{$event->organization->slug}/events/{$event->slug}?lang=pt", ['Accept-Language' => 'fr'])
        ->assertInertia(fn (Assert $page) => $page
            ->where('event.locale', 'es')
            ->where('event.name', 'Congreso Parques'));
});

it('CA-002-14: a draft or archived event answers 404', function (EventStatus $status): void {
    $organization = Organization::factory()->create();
    $event = Event::factory()->create(['organization_id' => $organization->id, 'status' => $status, 'slug' => 'congreso']);

    $this->get("/{$organization->slug}/events/{$event->slug}")->assertNotFound();
})->with([EventStatus::Draft, EventStatus::Archived]);

it('CA-002-14: a soft-deleted event answers 404', function (): void {
    $event = publishedEvent();
    $event->delete();

    $this->get("/{$event->organization->slug}/events/{$event->slug}")->assertNotFound();
});

it('CA-002-14: an event of another organization answers 404 under this organization slug', function (): void {
    $other = publishedEvent();
    $organization = Organization::factory()->create();

    $this->get("/{$organization->slug}/events/{$other->slug}")->assertNotFound();
});

it('CA-002-14: a nonexistent organization answers 404', function (): void {
    $event = publishedEvent();

    $this->get("/no-existe/events/{$event->slug}")->assertNotFound();
});

it('CA-002-14: a nonexistent event answers 404', function (): void {
    $organization = Organization::factory()->create();

    $this->get("/{$organization->slug}/events/no-existe")->assertNotFound();
});

it('CA-002-15: the response exposes no internal identifiers, team emails or other organizations', function (): void {
    $event = publishedEvent();
    $otherOrganization = Organization::factory()->create(['name' => 'Otra Organización Secreta']);
    publishedEvent($otherOrganization, ['slug' => 'otro', 'name' => ['es' => 'Evento Ajeno']]);

    $response = $this->get("/{$event->organization->slug}/events/{$event->slug}")->assertOk();
    $body = $response->getContent();

    expect($body)->not->toContain($event->id)
        ->and($body)->not->toContain($event->organization_id)
        ->and($body)->not->toContain($otherOrganization->id)
        ->and($body)->not->toContain('Evento Ajeno')
        ->and($body)->not->toContain('Otra Organización Secreta')
        ->and($body)->not->toContain('@');
});

it('CA-002-13: no session cookie is needed and none is required to answer', function (): void {
    $event = publishedEvent();

    $this->get("/{$event->organization->slug}/events/{$event->slug}")->assertOk();

    $this->assertGuest();
});

it('CA-002-15: the interface texts follow the language of the page, not only the event content', function (): void {
    $event = publishedEvent();

    $this->get("/{$event->organization->slug}/events/{$event->slug}?lang=en")
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'en')
            ->where('translations.layout.skip_to_content', 'Skip to content')
            ->where('translations.event.when', 'Dates and time'));

    $this->get("/{$event->organization->slug}/events/{$event->slug}?lang=es")
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'es')
            ->where('translations.layout.skip_to_content', 'Saltar al contenido'));
});
