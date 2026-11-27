<?php

declare(strict_types=1);

use App\Actions\Landings\CreateCallLanding;
use App\Enums\CallStatus;
use App\Enums\EventStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\EditCallLanding;
use App\Filament\Resources\Events\Pages\EditEventLanding;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\EventLanding;
use App\Models\LandingImage;
use App\Models\Organization;
use App\Models\Track;
use App\Models\User;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Landing propia de cada convocatoria (plan 033): su editor, su guardado y su página pública.
 */

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake(MediaDisk::name());
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

function clCall(Organization $organization, Event $event, string $slug = 'ponencias'): CallForSubmissions
{
    return CurrentOrganization::run($organization, fn (): CallForSubmissions => CallForSubmissions::factory()->published()->create([
        'event_id' => $event->id,
        'slug' => $slug,
        'title' => ['es' => 'Ponencias '.$slug, 'en' => 'Talks'],
        'description' => ['es' => 'Envía tu ponencia', 'en' => 'Send your talk'],
        'locales' => ['es', 'en'],
    ]));
}

function clLogin(Organization $organization, User $user): void
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
}

function clEditor(Organization $organization, Event $event, CallForSubmissions $call): EditCallLanding
{
    $page = Livewire::test(EditCallLanding::class, ['record' => $event->id, 'call' => $call->id])->instance();
    assert($page instanceof EditCallLanding);

    return $page;
}

/** @return array<string, mixed> */
function clProps(Organization $organization, Event $event, CallForSubmissions $call): array
{
    $html = Livewire::test(EditCallLanding::class, ['record' => $event->id, 'call' => $call->id])->html();
    preg_match('/data-props="([^"]*)"/', $html, $matches);

    return json_decode(html_entity_decode($matches[1] ?? ''), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<array<string, mixed>> */
function clDraft(EventLanding $landing): array
{
    return array_values(array_map(
        fn (array $block): array => ['id' => $block['id'], 'type' => $block['type']] + array_filter($block, fn (mixed $value): bool => $value !== null),
        $landing->draft_blocks,
    ));
}

function clUrl(Organization $organization, Event $event, string $callId): string
{
    return EventResource::getUrl('call-landing', ['record' => $event->id, 'call' => $callId], panel: 'admin', tenant: $organization);
}

const CL_TEMPLATE_TYPES = ['call_hero', 'call_dates', 'call_axes', 'call_requirements', 'call_steps', 'faq', 'call_promos'];

it('CA-033-1: opening the editor of a call creates ITS OWN landing, in draft, with the call template; the event landing is not touched', function (): void {
    [$organization, $event, $admin] = formScenario();
    $call = clCall($organization, $event);
    clLogin($organization, $admin);
    $eventLanding = EventLanding::query()->where('event_id', $event->id)->whereNull('call_id')->sole();

    clProps($organization, $event, $call);

    $own = EventLanding::query()->where('call_id', $call->id)->sole();
    expect($own->id)->not->toBe($eventLanding->id)
        ->and($own->event_id)->toBe($event->id)
        ->and(array_column($own->draft_blocks, 'type'))->toBe(CL_TEMPLATE_TYPES)
        ->and($own->published_blocks)->toBeNull()
        ->and(array_column($eventLanding->fresh()->draft_blocks, 'type'))->toBe(['hero', 'event_info', 'calls', 'tracks']);

    // Abrirlo otra vez no crea otra.
    clProps($organization, $event, $call);
    expect(EventLanding::query()->where('call_id', $call->id)->count())->toBe(1)
        ->and(EventLanding::query()->where('event_id', $event->id)->count())->toBe(2)
        // La landing del evento y la de la convocatoria no se confunden.
        ->and($event->landing()->sole()->id)->toBe($eventLanding->id);
});

it('CA-033-2: the editor of a call receives its own blocks, the template and the live data of the call', function (): void {
    [$organization, $event, $admin] = formScenario();
    $event->forceFill(['status' => EventStatus::Published])->save();
    $call = clCall($organization, $event);
    clLogin($organization, $admin);

    $props = clProps($organization, $event, $call);

    expect($props['kind'])->toBe('call')
        ->and(array_column($props['blocks'], 'type'))->toBe(CL_TEMPLATE_TYPES)
        ->and(array_column($props['template'], 'type'))->toBe(CL_TEMPLATE_TYPES)
        ->and($props['preview']['call']['call']['title'])->toBe('Ponencias ponencias')
        ->and($props['preview']['call'])->toHaveKeys(['call', 'logoUrl', 'liveItems', 'form', 'promos'])
        ->and($props['translations']['call_template']['axes_title'])->toBe('Ejes temáticos')
        ->and($props['publicUrl'])->toBe("/{$organization->slug}/events/{$event->slug}/call/ponencias");
});

it('CA-033-3: the editor of the event keeps its kind, has no template and no call data', function (): void {
    [$organization, $event, $admin] = formScenario();
    clLogin($organization, $admin);

    $html = Livewire::test(EditEventLanding::class, ['record' => $event->id])->html();
    preg_match('/data-props="([^"]*)"/', $html, $matches);
    $props = json_decode(html_entity_decode($matches[1] ?? ''), true, flags: JSON_THROW_ON_ERROR);

    expect($props['kind'])->toBe('event')->and($props['template'])->toBeNull()->and($props['preview'])->not->toHaveKey('call');
});

it('CA-033-4: the sections of a call are saved and published in its own landing, and only there', function (): void {
    [$organization, $event, $admin] = formScenario();
    $call = clCall($organization, $event);
    clLogin($organization, $admin);
    clProps($organization, $event, $call);
    $page = clEditor($organization, $event, $call);
    $landing = fn (): EventLanding => EventLanding::query()->where('call_id', $call->id)->sole();
    $blocks = clDraft($landing());

    $blocks[0]['title'] = ['es' => 'Mi título', 'en' => ''];
    $result = $page->publish($blocks, 1);

    expect($result['ok'])->toBeTrue()
        ->and($landing()->published_blocks[0]['title'])->toBe(['es' => 'Mi título'])
        ->and(EventLanding::currentPublishedForCall($call)?->id)->toBe($landing()->id)
        // La landing del evento no cambia: sigue sin bloques de convocatoria.
        ->and(EventLanding::currentPublished($event)?->id)->not->toBe($landing()->id);
});

it('CA-033-5: a call section cannot be saved in the landing of the EVENT', function (): void {
    [$organization, $event, $admin] = formScenario();
    clLogin($organization, $admin);
    $page = Livewire::test(EditEventLanding::class, ['record' => $event->id])->instance();
    assert($page instanceof EditEventLanding);
    $blocks = clDraft(EventLanding::query()->where('event_id', $event->id)->sole());
    $blocks[] = ['id' => 'new-1', 'type' => 'call_hero'];

    $result = $page->saveDraft($blocks, 1);

    expect($result['ok'])->toBeFalse()->and($result['errors'])->toHaveKey('blocks.4.type');
});

it('CA-033-6: invalid texts of a call section come back as field errors (a venue needs its detail, a step an existing icon)', function (): void {
    [$organization, $event, $admin] = formScenario();
    $call = clCall($organization, $event);
    clLogin($organization, $admin);
    clProps($organization, $event, $call);
    $page = clEditor($organization, $event, $call);
    $blocks = clDraft(EventLanding::query()->where('call_id', $call->id)->sole());
    $blocks[1]['venueName'] = ['es' => 'Parque Central'];
    $blocks[4]['items'][0]['icon'] = 'no-existe';

    $result = $page->saveDraft($blocks, 1);

    expect($result['ok'])->toBeFalse()->and($result['errors'])->toHaveKeys(['blocks.1.venueNote.es', 'blocks.4.items.0.icon']);
});

it('CA-033-7: a call of another event, an unknown id or a visitor cannot open the call editor', function (): void {
    [$organization, $event, $admin] = formScenario();
    [, $otherEvent] = formScenario();
    $foreignCall = CurrentOrganization::run($otherEvent->organization, fn (): CallForSubmissions => CallForSubmissions::factory()->create(['event_id' => $otherEvent->id]));
    $own = clCall($organization, $event);

    $this->actingAs($admin)->get(clUrl($organization, $event, $own->id))->assertOk()->assertSee('data-landing-editor', false);
    $this->actingAs($admin)->get(clUrl($organization, $event, $foreignCall->id))->assertNotFound();
    $this->actingAs($admin)->get(clUrl($organization, $event, 'no-es-un-uuid'))->assertNotFound();
    Auth::logout();
    $this->get(clUrl($organization, $event, $own->id))->assertRedirect();
});

it('CA-033-8: without events.manage the call editor is refused', function (): void {
    [$organization, $event] = formScenario();
    $call = clCall($organization, $event);
    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);

    expect($this->actingAs($reviewer)->get(clUrl($organization, $event, $call->id))->status())->toBeIn([403, 404]);
});

it('CA-033-9: creating the landing of a call is idempotent and needs events.manage', function (): void {
    [$organization, $event, $admin] = formScenario();
    $call = clCall($organization, $event);
    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);

    $first = app(CreateCallLanding::class)->handle($admin, $call);
    $second = app(CreateCallLanding::class)->handle($admin, $call);

    expect($second->id)->toBe($first->id)
        ->and(fn () => app(CreateCallLanding::class)->handle($reviewer, $call))->toThrow(AuthorizationException::class);
});

/** Publica la landing propia de una convocatoria con las secciones de la plantilla. */
function clPublish(Organization $organization, Event $event, User $admin, CallForSubmissions $call, ?Closure $adjust = null): EventLanding
{
    clLogin($organization, $admin);
    clProps($organization, $event, $call);
    $landing = EventLanding::query()->where('call_id', $call->id)->sole();
    $blocks = clDraft($landing);

    if ($adjust !== null) {
        $blocks = $adjust($blocks);
    }

    $result = clEditor($organization, $event, $call)->publish($blocks, $landing->version);
    expect($result['ok'])->toBeTrue();

    return EventLanding::query()->where('call_id', $call->id)->sole();
}

it('CA-033-10: the public page of a call uses its own landing once published, with live data, and the automatic one before', function (): void {
    [$organization, $event, $admin] = formScenario();
    $event->forceFill(['status' => EventStatus::Published])->save();
    $call = clCall($organization, $event);
    $other = clCall($organization, $event, 'posters');
    CurrentOrganization::run($organization, fn () => Track::factory()->create(['event_id' => $event->id, 'name' => ['es' => 'Eje uno']]));
    $url = "/{$organization->slug}/events/{$event->slug}/call/ponencias?lang=es";

    // Sin landing publicada: la automática de siempre.
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Calls/Show')->where('callLanding', null)->has('landing'));

    clPublish($organization, $event, $admin, $call, function (array $blocks): array {
        $blocks[0]['title'] = ['es' => 'Título propio', 'en' => ''];
        $blocks[1]['venueName'] = ['es' => 'Parque Central'];
        $blocks[1]['venueNote'] = ['es' => 'Mazatlán'];

        return $blocks;
    });

    Auth::logout();
    CurrentOrganization::forget();
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Calls/Show')
        ->has('callLanding.blocks', 7)
        ->where('callLanding.blocks.0.type', 'call_hero')
        ->where('callLanding.blocks.0.title', 'Título propio')
        // Lo vivo sale de la convocatoria y del evento, no de la landing.
        ->where('callLanding.blocks.0.lede', 'Envía tu ponencia')
        ->where('callLanding.blocks.1.venue', ['name' => 'Parque Central', 'note' => 'Mazatlán'])
        ->where('callLanding.blocks.2.axes.0.name', 'Eje uno')
        ->where('callLanding.blocks.3.items.0', fn ($line) => str_contains((string) $line, 'persona') || str_contains((string) $line, 'individual'))
        ->has('callLanding.blocks.6.promos', 1)
        ->where('callLanding.blocks.6.promos.0.title', $other->getTranslation('title', 'es'))
        ->where('callLanding.blocks.6.promos.0.button', 'Ver convocatoria'));
});

it('CA-033-11: a call landing in draft changes nothing on the public page, and the event page ignores call landings', function (): void {
    [$organization, $event, $admin] = formScenario();
    $event->forceFill(['status' => EventStatus::Published])->save();
    $call = clCall($organization, $event);
    clLogin($organization, $admin);
    clProps($organization, $event, $call);
    Auth::logout();
    CurrentOrganization::forget();

    $this->get("/{$organization->slug}/events/{$event->slug}/call/ponencias?lang=es")
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('callLanding', null));

    // La página del evento solo mira la landing del evento.
    expect(CurrentOrganization::run($organization, fn () => EventLanding::currentPublished($event)?->call_id))->toBeNull();
});

it('CA-033-12: the cover image of a call landing is served only while it is published and the call is not a draft', function (): void {
    [$organization, $event, $admin] = formScenario();
    $event->forceFill(['status' => EventStatus::Published])->save();
    $call = clCall($organization, $event);
    $landing = clPublish($organization, $event, $admin, $call);
    $path = "organizations/{$organization->id}/events/{$event->id}/media/landing/".Str::uuid().'.png';
    Storage::disk(MediaDisk::name())->put($path, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
    $image = CurrentOrganization::run($organization, fn (): LandingImage => LandingImage::factory()->create([
        'event_id' => $event->id,
        'event_landing_id' => $landing->id,
        'path' => $path,
        'mime' => 'image/png',
    ]));
    $url = "/{$organization->slug}/events/{$event->slug}/landing/images/{$image->id}";
    Auth::logout();
    CurrentOrganization::forget();

    // Solo en el borrador: no se sirve.
    $this->get($url)->assertNotFound();

    // Publicada con la imagen en la portada: se sirve.
    CurrentOrganization::run($organization, function () use ($landing, $image): void {
        $blocks = $landing->fresh()->draft_blocks;
        $blocks[0]['image'] = ['id' => $image->id];
        $blocks[0]['imageAlt'] = ['es' => 'Portada'];
        $landing->forceFill(['draft_blocks' => $blocks, 'published_blocks' => $blocks, 'published_at' => now()])->save();
    });
    $this->get($url)->assertOk();

    // La convocatoria vuelve a borrador: la imagen deja de verse.
    CurrentOrganization::run($organization, fn () => $call->forceFill(['status' => CallStatus::Draft])->save());
    $this->get($url)->assertNotFound();
});
