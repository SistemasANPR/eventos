<?php

declare(strict_types=1);

use App\Enums\CallStatus;
use App\Enums\EventStatus;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Models\SubmissionType;
use App\Models\Track;
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
 * Convocatoria publicada de un evento publicado, en es y en.
 *
 * @param  array<string, mixed>  $attributes
 */
function publicCall(?Organization $organization = null, array $attributes = [], string $eventSlug = 'congreso'): CallForSubmissions
{
    $organization ??= Organization::factory()->create();

    $event = Event::factory()->published()->create([
        'organization_id' => $organization->id,
        'name' => ['es' => 'Congreso Parques', 'en' => 'Parks Congress'],
        'slug' => $eventSlug,
        'timezone' => 'America/Mexico_City',
        'locales' => ['es', 'en', 'pt'],
        'default_locale' => 'es',
    ]);

    $type = SubmissionType::factory()->create([
        'organization_id' => $organization->id,
        'event_id' => $event->id,
        'name' => ['es' => 'Sesión', 'en' => 'Session'],
    ]);

    return CallForSubmissions::factory()->published()->create($attributes + [
        'organization_id' => $organization->id,
        'event_id' => $event->id,
        'submission_type_id' => $type->id,
        'slug' => 'ponencias',
        'title' => ['es' => 'Convocatoria de ponencias', 'en' => 'Call for talks'],
        'description' => ['es' => 'Envía tu propuesta', 'en' => 'Send your proposal'],
        'locales' => ['es', 'en'],
        'opens_at' => '2027-01-10 15:00:00',
        'closes_at' => '2027-02-15 23:00:00',
        'results_at' => '2027-03-01 18:00:00',
    ]);
}

function callUrl(CallForSubmissions $call, ?string $organizationSlug = null): string
{
    $organization = $organizationSlug ?? Organization::query()->whereKey($call->organization_id)->value('slug');
    $event = Event::query()->withoutGlobalScopes()->whereKey($call->event_id)->value('slug');

    return "/{$organization}/events/{$event}/call/{$call->slug}";
}

it('CA-004-19: shows the call in the requested language with dates in UTC and the event timezone', function (): void {
    $call = publicCall();

    $this->get(callUrl($call).'?lang=en')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Calls/Show')
            ->where('call.title', 'Call for talks')
            ->where('call.description', 'Send your proposal')
            ->where('call.submissionType', 'Session')
            ->where('call.locale', 'en')
            ->where('call.locales', ['es', 'en'])
            ->where('call.opensAt', '2027-01-10T15:00:00Z')
            ->where('call.closesAt', '2027-02-15T23:00:00Z')
            ->where('call.resultsAt', '2027-03-01T18:00:00Z')
            ->where('call.timezone', 'America/Mexico_City')
            ->where('event.name', 'Parks Congress')
            ->where('locale', 'en'));
});

it('CA-004-19: falls back to the base language when the language is not one of the call', function (): void {
    $call = publicCall();

    // `pt` es del evento pero no de la convocatoria; `fr` no es de ninguno.
    $this->get(callUrl($call).'?lang=pt', ['Accept-Language' => 'fr'])
        ->assertInertia(fn (Assert $page) => $page
            ->where('call.locale', 'es')
            ->where('call.title', 'Convocatoria de ponencias'));
});

it('CA-004-19: uses Accept-Language among the languages of the call', function (): void {
    $call = publicCall();

    $this->get(callUrl($call), ['Accept-Language' => 'en'])
        ->assertInertia(fn (Assert $page) => $page->where('call.locale', 'en'));
});

it('CA-004-19: publishes the manual state of a paused or closed call', function (CallStatus $status, string $state): void {
    $call = publicCall(attributes: ['status' => $status]);

    $this->get(callUrl($call))
        ->assertInertia(fn (Assert $page) => $page->where('call.state', $state));
})->with([
    [CallStatus::Paused, 'paused'],
    [CallStatus::Closed, 'closed'],
]);

it('CA-004-19: computes the state from the dates on each request', function (): void {
    $call = publicCall();

    $this->travelTo('2027-01-01 00:00:00');
    $this->get(callUrl($call))->assertInertia(fn (Assert $page) => $page->where('call.state', 'scheduled'));

    $this->travelTo('2027-01-10 15:00:00');
    $this->get(callUrl($call))->assertInertia(fn (Assert $page) => $page->where('call.state', 'open'));

    $this->travelTo('2027-02-15 23:00:00');
    $this->get(callUrl($call))->assertInertia(fn (Assert $page) => $page->where('call.state', 'closed'));
});

it('CA-004-20: a draft, another organization or a nonexistent call answer 404', function (): void {
    $draft = publicCall(attributes: ['status' => CallStatus::Draft]);
    $call = publicCall();
    $other = publicCall(Organization::factory()->create(), ['slug' => 'ajena'], 'ajeno');
    $organizationSlug = Organization::query()->whereKey($call->organization_id)->value('slug');

    $this->get(callUrl($draft))->assertNotFound();
    $this->get("/{$organizationSlug}/events/congreso/call/no-existe")->assertNotFound();
    // La convocatoria de B bajo el slug de la organización A.
    $this->get(callUrl($other, $organizationSlug))->assertNotFound();
    $this->get('/no-existe/events/congreso/call/ponencias')->assertNotFound();
});

it('CA-004-20: a soft-deleted call or a call of an unpublished event answers 404', function (): void {
    $call = publicCall();
    $unpublished = publicCall(eventSlug: 'borrador');
    Event::query()->withoutGlobalScopes()->whereKey($unpublished->event_id)->update(['status' => EventStatus::Draft]);

    $this->get(callUrl($unpublished))->assertNotFound();

    $call->delete();
    $this->get(callUrl($call))->assertNotFound();
});

it('CA-004-20: a call of another event of the same organization answers 404 under this event', function (): void {
    $call = publicCall();
    $otherEvent = Event::factory()->published()->create(['organization_id' => $call->organization_id, 'slug' => 'otro-evento']);
    $organizationSlug = Organization::query()->whereKey($call->organization_id)->value('slug');

    $this->get("/{$organizationSlug}/events/{$otherEvent->slug}/call/{$call->slug}")->assertNotFound();
});

it('CA-004-21: lists only active tracks in order and omits the null results date', function (): void {
    $call = publicCall(attributes: ['results_at' => null]);
    $base = ['organization_id' => $call->organization_id, 'event_id' => $call->event_id];
    Track::factory()->create($base + ['name' => ['es' => 'Segundo'], 'position' => 2]);
    Track::factory()->create($base + ['name' => ['es' => 'Primero', 'en' => 'First'], 'description' => ['es' => 'Detalle'], 'position' => 1]);
    Track::factory()->archived()->create($base + ['name' => ['es' => 'Archivado'], 'position' => 3]);

    $this->get(callUrl($call).'?lang=en')
        ->assertInertia(fn (Assert $page) => $page
            ->has('call.tracks', 2)
            ->where('call.tracks.0.name', 'First')
            ->where('call.tracks.0.description', 'Detalle')
            ->where('call.tracks.1.name', 'Segundo')
            ->where('call.tracks.1.description', null)
            ->missing('call.resultsAt'));
});

it('CA-004-21: the props carry no internal identifiers, team data or other organizations', function (): void {
    $call = publicCall();
    Track::factory()->create(['organization_id' => $call->organization_id, 'event_id' => $call->event_id]);
    $otherOrganization = Organization::factory()->create(['name' => 'Otra Organización Secreta']);
    publicCall($otherOrganization);

    $body = (string) $this->get(callUrl($call))->assertOk()->getContent();

    expect($body)->not->toContain($call->id)
        ->and($body)->not->toContain($call->organization_id)
        ->and($body)->not->toContain($call->event_id)
        ->and($body)->not->toContain($call->submission_type_id)
        ->and($body)->not->toContain($otherOrganization->id)
        ->and($body)->not->toContain('Otra Organización Secreta')
        ->and($body)->not->toContain('@');
});

it('links back to the event and to the privacy notice only when it is published', function (): void {
    $call = publicCall();
    $organizationSlug = Organization::query()->whereKey($call->organization_id)->value('slug');

    $this->get(callUrl($call), ['Accept-Language' => 'es'])
        ->assertInertia(fn (Assert $page) => $page
            ->where('eventUrl', "/{$organizationSlug}/events/congreso?lang=es")
            ->where('privacyUrl', null));

    PrivacyNotice::factory()->published()->create(['organization_id' => $call->organization_id, 'event_id' => $call->event_id]);

    $this->get(callUrl($call), ['Accept-Language' => 'es'])
        ->assertInertia(fn (Assert $page) => $page
            ->where('privacyUrl', "/{$organizationSlug}/events/congreso/privacy"));
});

it('CA-004-23: the organization is in context before the model binding and is cleared afterwards', function (): void {
    $call = publicCall();
    publicCall(Organization::factory()->create());

    // Sin contexto previo: el binding de la convocatoria solo funciona si la ruta lo fijó antes.
    CurrentOrganization::forget();

    $this->get(callUrl($call))->assertOk();

    expect(CurrentOrganization::has())->toBeFalse();
});
