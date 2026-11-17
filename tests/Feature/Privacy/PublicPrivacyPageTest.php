<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Organization;
use App\Models\PrivacyNotice;
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

/**
 * @param  array<string, string>  $body
 * @return array{0: Organization, 1: Event, 2: PrivacyNotice}
 */
function publishedNoticeScenario(array $body = ['es' => '<p>Aviso en español</p>', 'en' => '<p>Notice in English</p>']): array
{
    $organization = Organization::factory()->create(['slug' => 'parques']);
    $event = Event::factory()->published()->create([
        'organization_id' => $organization->id,
        'slug' => 'congreso',
        'name' => ['es' => 'Congreso', 'en' => 'Congress'],
        'locales' => ['es', 'en'],
        'default_locale' => 'es',
    ]);
    $notice = PrivacyNotice::factory()->published()->create(['event_id' => $event->id, 'body' => $body]);

    return [$organization, $event, $notice];
}

it('CA-003-16: a visitor sees the notice in English when the event has it', function (): void {
    publishedNoticeScenario();

    $this->get('/parques/events/congreso/privacy?lang=en')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Events/Privacy')
            ->where('event.locale', 'en')
            ->where('event.name', 'Congress')
            ->where('notice.locale', 'en')
            ->where('notice.body', '<p>Notice in English</p>')
            ->where('notice.version', 1)
            ->where('organization.slug', 'parques'));
});

it('CA-003-16: a visitor sees the notice in Spanish by default', function (): void {
    publishedNoticeScenario();

    $this->get('/parques/events/congreso/privacy', ['Accept-Language' => 'es-MX,es;q=0.9'])
        ->assertInertia(fn (Assert $page) => $page
            ->where('notice.locale', 'es')
            ->where('notice.body', '<p>Aviso en español</p>'));
});

it('CA-003-16: when the language is missing from the notice the base language is shown and says so', function (): void {
    publishedNoticeScenario(['es' => '<p>Solo en español</p>']);

    $this->get('/parques/events/congreso/privacy?lang=en')
        ->assertInertia(fn (Assert $page) => $page
            ->where('event.locale', 'en')
            ->where('notice.locale', 'es')
            ->where('notice.body', '<p>Solo en español</p>'));
});

it('CA-003-16: the page gives the UTC publication instant and the link back to the event', function (): void {
    [, , $notice] = publishedNoticeScenario();

    $this->get('/parques/events/congreso/privacy?lang=en')
        ->assertInertia(fn (Assert $page) => $page
            ->where('notice.publishedAt', $notice->published_at->utc()->format('Y-m-d\TH:i:s\Z'))
            ->where('eventUrl', '/parques/events/congreso?lang=en'));
});

it('CA-003-17: an event without a published notice answers 404', function (): void {
    $organization = Organization::factory()->create(['slug' => 'parques']);
    Event::factory()->published()->create(['organization_id' => $organization->id, 'slug' => 'congreso']);

    $this->get('/parques/events/congreso/privacy')->assertNotFound();
});

it('CA-003-17: an event with only a draft notice answers 404', function (): void {
    $organization = Organization::factory()->create(['slug' => 'parques']);
    $event = Event::factory()->published()->create(['organization_id' => $organization->id, 'slug' => 'congreso']);
    PrivacyNotice::factory()->create(['event_id' => $event->id]);

    $this->get('/parques/events/congreso/privacy')->assertNotFound();
});

it('CA-003-17: the notice of a draft or archived event is not public', function (string $state): void {
    $organization = Organization::factory()->create(['slug' => 'parques']);
    $event = Event::factory()->{$state}()->create(['organization_id' => $organization->id, 'slug' => 'congreso']);
    PrivacyNotice::factory()->published()->create(['event_id' => $event->id]);

    $this->get('/parques/events/congreso/privacy')->assertNotFound();
})->with(['archived']);

it('CA-003-17: a draft event answers 404 even with a published notice', function (): void {
    $organization = Organization::factory()->create(['slug' => 'parques']);
    $event = Event::factory()->create(['organization_id' => $organization->id, 'slug' => 'congreso']);
    PrivacyNotice::factory()->published()->create(['event_id' => $event->id]);

    $this->get('/parques/events/congreso/privacy')->assertNotFound();
});

it('CA-003-17: the notice of an event of another organization answers 404 under this slug', function (): void {
    publishedNoticeScenario();
    Organization::factory()->create(['slug' => 'otra']);

    $this->get('/otra/events/congreso/privacy')->assertNotFound();
});

it('CA-003-20: the public page serves the current published version, never a newer draft', function (): void {
    [, $event] = publishedNoticeScenario();
    PrivacyNotice::factory()->create(['event_id' => $event->id, 'version' => 2, 'body' => ['es' => '<p>Borrador secreto</p>']]);

    $this->get('/parques/events/congreso/privacy')
        ->assertInertia(fn (Assert $page) => $page->where('notice.version', 1));
});

it('CA-003-15: the page exposes no internal identifiers', function (): void {
    [$organization, $event, $notice] = publishedNoticeScenario();

    $body = $this->get('/parques/events/congreso/privacy')->assertOk()->getContent();

    expect($body)->not->toContain($organization->id)
        ->and($body)->not->toContain($event->id)
        ->and($body)->not->toContain($notice->id)
        ->and($body)->not->toContain((string) $notice->published_by);
});

it('CA-003-19: the event page links to the notice only when one is published', function (): void {
    publishedNoticeScenario();

    $this->get('/parques/events/congreso')
        ->assertInertia(fn (Assert $page) => $page->where('privacyUrl', '/parques/events/congreso/privacy'));
});

it('CA-003-19: the event page shows no link without a published notice', function (): void {
    $organization = Organization::factory()->create(['slug' => 'parques']);
    $event = Event::factory()->published()->create(['organization_id' => $organization->id, 'slug' => 'congreso']);
    PrivacyNotice::factory()->create(['event_id' => $event->id]);

    $this->get('/parques/events/congreso')
        ->assertInertia(fn (Assert $page) => $page->where('privacyUrl', null));
});
