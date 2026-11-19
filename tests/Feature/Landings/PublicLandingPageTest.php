<?php

declare(strict_types=1);

use App\Enums\CallStatus;
use App\Enums\EventStatus;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\EventLanding;
use App\Models\LandingImage;
use App\Models\Organization;
use App\Models\Track;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake(MediaDisk::name());
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** Evento publicado con idiomas es y en, en zona de México. */
function pageEvent(?Organization $organization = null): Event
{
    $organization ??= Organization::factory()->create();

    return Event::factory()->published()->create([
        'organization_id' => $organization->id,
        'name' => ['es' => 'Congreso Parques', 'en' => 'Parks Congress'],
        'description' => ['es' => 'Descripción', 'en' => 'Description'],
        'slug' => 'congreso',
        'timezone' => 'America/Mexico_City',
        'locales' => ['es', 'en'],
        'default_locale' => 'es',
        'time_format' => '24h',
    ]);
}

/**
 * La landing del evento con esos bloques ya publicados (y el borrador igual, salvo que se indique).
 *
 * @param  list<array<string, mixed>>  $published
 * @param  list<array<string, mixed>>|null  $draft
 */
function pageLanding(Event $event, array $published, ?array $draft = null): EventLanding
{
    $withIds = fn (array $blocks): array => array_map(fn (array $block): array => ['id' => (string) Str::uuid(), ...$block], $blocks);
    $published = $withIds($published);

    return CurrentOrganization::run($event->organization, fn (): EventLanding => EventLanding::factory()->create([
        'event_id' => $event->id,
        'draft_blocks' => $draft ?? $published,
        'published_blocks' => $published,
        'published_at' => now(),
    ]));
}

function pageUrl(Event $event, string $query = '?lang=es'): string
{
    return "/{$event->organization->slug}/events/{$event->slug}{$query}";
}

/**
 * @param  array<string, mixed>  $attributes
 */
function pageCall(Event $event, string $title, array $attributes = []): CallForSubmissions
{
    return CurrentOrganization::run($event->organization, fn (): CallForSubmissions => CallForSubmissions::factory()->create([
        'event_id' => $event->id,
        'title' => ['es' => $title, 'en' => "{$title} (en)"],
        'locales' => ['es', 'en'],
        'status' => CallStatus::Published,
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addDays(10),
        ...$attributes,
    ]));
}

it('CA-022-21: shows the blocks in the saved order, in the requested language, falling back to the base one', function (): void {
    $event = pageEvent();
    pageLanding($event, [
        ['type' => 'rich_text', 'html' => ['es' => '<p>Texto en español</p>', 'en' => '<p>English text</p>'], 'width' => 'narrow'],
        ['type' => 'hero', 'title' => ['es' => 'Bienvenidos', 'en' => 'Welcome'], 'subtitle' => ['es' => 'Solo español'], 'tone' => 'accent', 'align' => 'center'],
        ['type' => 'event_info'],
        ['type' => 'spacer', 'size' => 'lg'],
    ]);

    $this->get(pageUrl($event, '?lang=en'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Events/Show')
            ->where('landing.blocks.0.type', 'rich_text')
            ->where('landing.blocks.0.html', '<p>English text</p>')
            ->where('landing.blocks.1.type', 'hero')
            ->where('landing.blocks.1.title', 'Welcome')
            // Falta el subtítulo en inglés: se ve el del idioma base.
            ->where('landing.blocks.1.subtitle', 'Solo español')
            ->where('landing.blocks.2.type', 'event_info')
            // Fechas en UTC con la zona y el formato de hora del evento para que el cliente las formatee.
            ->where('landing.blocks.2.startsAt', '2027-03-10T15:00:00Z')
            ->where('landing.blocks.2.timezone', 'America/Mexico_City')
            ->where('landing.blocks.2.timeFormat', '24h')
            ->where('landing.blocks.3.type', 'spacer')
            ->where('landing.blocks.3.size', 'lg'));
});

it('CA-022-22: an event without published landing shows the current page, identical to today', function (): void {
    $withoutLanding = pageEvent();
    $this->get(pageUrl($withoutLanding))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Events/Show')
            ->where('landing', null)
            ->where('event.name', 'Congreso Parques'));

    // Una landing solo con borrador (el caso de un evento anterior al plan) tampoco cambia la página.
    $draftOnly = pageEvent(Organization::factory()->create());
    CurrentOrganization::run($draftOnly->organization, fn () => EventLanding::factory()->create(['event_id' => $draftOnly->id]));

    $this->get(pageUrl($draftOnly))->assertOk()->assertInertia(fn (Assert $page) => $page->where('landing', null));
});

it('CA-022-17: a visitor sees the last published version, never the draft', function (): void {
    $event = pageEvent();
    pageLanding($event, [['type' => 'spacer', 'size' => 'sm']], [['id' => (string) Str::uuid(), 'type' => 'rich_text', 'html' => ['es' => '<p>Secreto del borrador</p>']]]);

    $response = $this->get(pageUrl($event))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page->has('landing.blocks', 1)->where('landing.blocks.0.type', 'spacer'));
    expect($response->getContent())->not->toContain('Secreto del borrador');
});

it('CA-022-23: a draft, archived, foreign or missing event answers 404, with or without landing', function (): void {
    $organization = Organization::factory()->create();
    $draft = Event::factory()->create(['organization_id' => $organization->id, 'slug' => 'borrador', 'status' => EventStatus::Draft]);
    $archived = Event::factory()->archived()->create(['organization_id' => $organization->id, 'slug' => 'archivado']);
    foreach ([$draft, $archived] as $event) {
        pageLanding($event, [['type' => 'spacer']]);
        $this->get(pageUrl($event))->assertNotFound();
    }

    $published = pageEvent($organization);
    pageLanding($published, [['type' => 'spacer']]);
    $otherOrganization = Organization::factory()->create();

    $this->get("/{$otherOrganization->slug}/events/{$published->slug}")->assertNotFound();
    $this->get("/{$organization->slug}/events/no-existe")->assertNotFound();
});

it('CA-022-24: the calls block lists only the published calls of the event and follows their dates and status', function (): void {
    $event = pageEvent();
    pageLanding($event, [['type' => 'calls', 'title' => ['es' => 'Participa']]]);
    pageCall($event, 'Ponencias');
    pageCall($event, 'Borrador oculto', ['status' => CallStatus::Draft]);
    pageCall(pageEvent(Organization::factory()->create()), 'De otro evento');

    $this->get(pageUrl($event))->assertInertia(fn (Assert $page) => $page
        ->where('landing.blocks.0.title', 'Participa')
        ->has('landing.blocks.0.calls', 1)
        ->where('landing.blocks.0.calls.0.title', 'Ponencias')
        ->where('landing.blocks.0.calls.0.state', 'open'));

    // Cambian las fechas de la convocatoria, no la landing: la página lo refleja.
    CurrentOrganization::run($event->organization, fn () => CallForSubmissions::query()->where('event_id', $event->id)->update(['closes_at' => now()->subHour()]));

    $this->get(pageUrl($event))->assertInertia(fn (Assert $page) => $page->where('landing.blocks.0.calls.0.state', 'closed'));
});

it('CA-022-25: an open call links its register button to the submission form', function (): void {
    $event = pageEvent();
    pageLanding($event, [['type' => 'calls']]);
    $call = pageCall($event, 'Ponencias');

    $this->get(pageUrl($event, '?lang=es'))->assertInertia(fn (Assert $page) => $page
        ->where('landing.blocks.0.calls.0.registerUrl', "/{$event->organization->slug}/events/{$event->slug}/call/{$call->slug}/submit?lang=es"));
});

it('CA-022-26: a scheduled, paused or closed call has no register link, and the direct link does not offer the form', function (string $label, array $attributes, string $state): void {
    $event = pageEvent();
    pageLanding($event, [['type' => 'calls']]);
    $call = pageCall($event, 'Ponencias', $attributes);

    $this->get(pageUrl($event))->assertInertia(fn (Assert $page) => $page
        ->where('landing.blocks.0.calls.0.state', $state)
        ->where('landing.blocks.0.calls.0.registerUrl', null));

    // El servidor lo hace cumplir aparte: el enlace directo no muestra el formulario.
    $this->get("/{$event->organization->slug}/events/{$event->slug}/call/{$call->slug}/submit")
        ->assertInertia(fn (Assert $page) => $page->where('form', null));
    // Y la landing sigue publicada en la misma URL.
    $this->get(pageUrl($event))->assertOk();
})->with([
    'scheduled' => fn (): array => ['scheduled', ['opens_at' => now()->addDay(), 'closes_at' => now()->addDays(10)], 'scheduled'],
    'paused' => fn (): array => ['paused', ['status' => CallStatus::Paused], 'paused'],
    'closed by status' => fn (): array => ['closed', ['status' => CallStatus::Closed], 'closed'],
    'closed by date' => fn (): array => ['closed', ['opens_at' => now()->subDays(10), 'closes_at' => now()->subDay()], 'closed'],
]);

it('CA-022-24: the tracks block lists the active tracks of the event', function (): void {
    $event = pageEvent();
    pageLanding($event, [['type' => 'tracks']]);
    CurrentOrganization::run($event->organization, function () use ($event): void {
        Track::factory()->create(['event_id' => $event->id, 'name' => ['es' => 'Biodiversidad'], 'position' => 1]);
        Track::factory()->create(['event_id' => $event->id, 'name' => ['es' => 'Archivado'], 'position' => 2, 'archived_at' => now()]);
    });

    $this->get(pageUrl($event))->assertInertia(fn (Assert $page) => $page
        ->has('landing.blocks.0.tracks', 1)
        ->where('landing.blocks.0.tracks.0.name', 'Biodiversidad'));
});

it('CA-022-27: the page props carry neither the draft nor internal ids nor team data', function (): void {
    $event = pageEvent();
    $call = pageCall($event, 'Ponencias');
    pageLanding($event, [['type' => 'hero', 'title' => ['es' => 'Hola'], 'tone' => 'accent', 'align' => 'center'], ['type' => 'calls']], [['id' => (string) Str::uuid(), 'type' => 'rich_text', 'html' => ['es' => '<p>BORRADOR-PRIVADO</p>']]]);

    $body = $this->get(pageUrl($event))->assertOk()->getContent();

    expect($body)->not->toContain($event->id)
        ->and($body)->not->toContain($event->organization_id)
        ->and($body)->not->toContain($call->id)
        ->and($body)->not->toContain($call->event_id)
        ->and($body)->not->toContain('BORRADOR-PRIVADO')
        ->and($body)->not->toContain('draft_blocks')
        ->and($body)->not->toContain('published_by')
        ->and($body)->not->toContain('@');
});

it('CA-022-28: the title, description and og:image come from the hero and the event', function (): void {
    $event = pageEvent();
    $landing = pageLanding($event, [['type' => 'spacer']]);
    $image = CurrentOrganization::run($event->organization, fn (): LandingImage => LandingImage::factory()->create(['event_landing_id' => $landing->id]));
    pageLandingReplace($landing, [
        ['type' => 'hero', 'title' => ['es' => 'Título del hero'], 'subtitle' => ['es' => 'Subtítulo del hero'], 'image' => ['id' => $image->id], 'tone' => 'accent', 'align' => 'center'],
    ]);

    $this->get(pageUrl($event))->assertInertia(fn (Assert $page) => $page
        ->where('landing.meta.title', 'Título del hero')
        ->where('landing.meta.description', 'Subtítulo del hero')
        ->where('landing.meta.imageUrl', url("/{$event->organization->slug}/events/{$event->slug}/landing/images/{$image->id}")));

    // Sin hero: el título y la descripción salen del evento.
    pageLandingReplace($landing, [['type' => 'spacer']]);
    $this->get(pageUrl($event))->assertInertia(fn (Assert $page) => $page
        ->where('landing.meta.title', 'Congreso Parques')
        ->where('landing.meta.description', 'Descripción')
        ->where('landing.meta.imageUrl', null));
});

/**
 * @param  list<array<string, mixed>>  $blocks
 */
function pageLandingReplace(EventLanding $landing, array $blocks): void
{
    $blocks = array_map(fn (array $block): array => ['id' => (string) Str::uuid(), ...$block], $blocks);

    CurrentOrganization::run(Organization::query()->findOrFail($landing->organization_id), function () use ($landing, $blocks): void {
        EventLanding::query()->whereKey($landing->id)->firstOrFail()->forceFill(['draft_blocks' => $blocks, 'published_blocks' => $blocks])->save();
    });
}

it('CA-022-11: a rich text with scripts never reaches the public page', function (): void {
    $event = pageEvent();
    // Aunque algo hubiera llegado a la base sin sanear, el servidor lo sanea otra vez al mostrar.
    pageLanding($event, [['type' => 'rich_text', 'html' => ['es' => '<p>Ok</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>'], 'width' => 'narrow']]);

    $body = $this->get(pageUrl($event))->assertOk()->getContent();

    expect($body)->not->toContain('<script>alert')->and($body)->not->toContain('javascript:alert');
});

/** @return array{0: Event, 1: EventLanding, 2: LandingImage, 3: string} evento, landing, imagen y la URL pública de la imagen */
function landingImageScenario(): array
{
    $event = pageEvent();
    $landing = pageLanding($event, [['type' => 'spacer']]);
    $path = "organizations/{$event->organization_id}/events/{$event->id}/media/landing/".Str::uuid().'.png';
    Storage::disk(MediaDisk::name())->put($path, UploadedFile::fake()->image('a.png', 4, 4)->getContent(), 'private');
    $image = CurrentOrganization::run($event->organization, fn (): LandingImage => LandingImage::factory()->create(['event_landing_id' => $landing->id, 'path' => $path]));

    return [$event, $landing, $image, "/{$event->organization->slug}/events/{$event->slug}/landing/images/{$image->id}"];
}

it('CA-022-32: an image only in the draft answers 404; one in the published blocks is served with nosniff', function (): void {
    [$event, $landing, $image, $url] = landingImageScenario();

    // Solo está en el borrador.
    CurrentOrganization::run($event->organization, fn () => EventLanding::query()->whereKey($landing->id)->firstOrFail()->forceFill([
        'draft_blocks' => [['id' => (string) Str::uuid(), 'type' => 'image_gallery', 'images' => [['image' => ['id' => $image->id], 'alt' => ['es' => 'x']]]]],
    ])->save());
    $this->get($url)->assertNotFound();

    // Publicada.
    pageLandingReplace($landing, [['type' => 'image_gallery', 'images' => [['image' => ['id' => $image->id], 'alt' => ['es' => 'x']]]]]);
    $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('CA-022-32: the image is refused when the event is not published, the landing is gone or the image is deleted', function (): void {
    [$event, $landing, $image, $url] = landingImageScenario();
    pageLandingReplace($landing, [['type' => 'image_gallery', 'images' => [['image' => ['id' => $image->id], 'alt' => ['es' => 'x']]]]]);
    $this->get($url)->assertOk();

    $this->get(str_replace($image->id, (string) Str::uuid(), $url))->assertNotFound();
    $this->get(str_replace($image->id, 'no-es-uuid', $url))->assertNotFound();

    CurrentOrganization::run($event->organization, fn () => LandingImage::query()->whereKey($image->id)->firstOrFail()->delete());
    $this->get($url)->assertNotFound();
});

it('CA-022-32: a draft event does not serve its landing images', function (): void {
    [$event, $landing, $image, $url] = landingImageScenario();
    pageLandingReplace($landing, [['type' => 'image_gallery', 'images' => [['image' => ['id' => $image->id], 'alt' => ['es' => 'x']]]]]);
    Event::query()->withoutGlobalScopes()->whereKey($event->id)->update(['status' => EventStatus::Draft]);

    $this->get($url)->assertNotFound();
});

it('CA-022-4: an image of another organization or event answers 404 on the public route', function (): void {
    [, $landing, $image] = landingImageScenario();
    pageLandingReplace($landing, [['type' => 'image_gallery', 'images' => [['image' => ['id' => $image->id], 'alt' => ['es' => 'x']]]]]);
    $other = pageEvent(Organization::factory()->create());
    pageLanding($other, [['type' => 'spacer']]);

    // La imagen de A pedida bajo el evento de B no existe para B.
    $this->get("/{$other->organization->slug}/events/{$other->slug}/landing/images/{$image->id}")->assertNotFound();
});
