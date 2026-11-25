<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\EditEventLanding;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\CallsRelationManager;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\EventLanding;
use App\Models\LandingImage;
use App\Models\Organization;
use App\Models\User;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

beforeEach(function (): void {
    Storage::fake(MediaDisk::name());
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

function editorUrl(Organization $organization, Event $event): string
{
    return EventResource::getUrl('landing', ['record' => $event->id], panel: 'admin', tenant: $organization);
}

function editorActAsAdmin(Organization $organization, User $user): void
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
}

function editorPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
}

it('CA-022-34: a user with events.manage opens the editor of the landing of an event', function (OrganizationRole $role): void {
    [$organization, $event, $user] = formScenario($role);

    $this->actingAs($user)->get(editorUrl($organization, $event))
        ->assertOk()
        ->assertSee('data-landing-editor', false);
})->with([
    'org_admin' => [OrganizationRole::OrgAdmin],
    'organizer' => [OrganizationRole::Organizer],
]);

it('CA-022-34: without events.manage the editor is refused', function (): void {
    [$organization, $event] = formScenario();
    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);

    expect($this->actingAs($reviewer)->get(editorUrl($organization, $event))->status())->toBeIn([403, 404]);
});

it('CA-022-34: «Diseñar landing» is offered on the event screen, on its card menu and on each call row, all to the same page', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    $call = CurrentOrganization::run($organization, fn (): CallForSubmissions => CallForSubmissions::factory()->create(['event_id' => $event->id]));
    editorActAsAdmin($organization, $user);
    $url = editorUrl($organization, $event);

    Livewire::test(ViewEvent::class, ['record' => $event->id])
        ->assertActionVisible('designLanding')
        ->assertActionHasUrl('designLanding', $url);

    Livewire::test(ListEvents::class)->assertTableActionVisible('designLanding', $event);

    Livewire::test(CallsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class])
        ->assertTableActionVisible('designLanding', $call);
});

it('CA-022-4: the editor of an event of another organization answers 404', function (): void {
    [$organizationA, , $adminA] = formScenario();
    [, $eventB] = formScenario();

    $this->actingAs($adminA)->get(editorUrl($organizationA, $eventB))->assertNotFound();
});

it('CA-022-8: a visitor without session cannot open the editor', function (): void {
    [$organization, $event] = formScenario();

    $this->get(editorUrl($organization, $event))->assertRedirect();
});

it('CA-022-2: opening the editor of an event without landing creates it with the template, in draft only', function (): void {
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $event = Event::factory()->create(['organization_id' => $organization->id, 'locales' => ['es', 'en'], 'default_locale' => 'es']);
    editorActAsAdmin($organization, $admin);

    expect(EventLanding::query()->where('event_id', $event->id)->exists())->toBeFalse();

    Livewire::test(EditEventLanding::class, ['record' => $event->id])->assertOk();

    $landing = EventLanding::query()->where('event_id', $event->id)->sole();
    expect(array_column($landing->draft_blocks, 'type'))->toBe(['hero', 'event_info', 'calls', 'tracks'])
        ->and($landing->published_blocks)->toBeNull();

    // Abrirlo otra vez no crea otra ni cambia la versión.
    Livewire::test(EditEventLanding::class, ['record' => $event->id])->assertOk();
    expect(EventLanding::query()->where('event_id', $event->id)->count())->toBe(1);
});

it('CA-022-36: the editor receives the draft with every language, the version and the live data of the event', function (): void {
    [$organization, $event, $admin] = formScenario();
    $event->forceFill(['status' => EventStatus::Published])->save();
    editorActAsAdmin($organization, $admin);

    $html = Livewire::test(EditEventLanding::class, ['record' => $event->id])->html();
    preg_match('/data-props="([^"]*)"/', $html, $matches);
    $props = json_decode(html_entity_decode($matches[1] ?? ''), true, flags: JSON_THROW_ON_ERROR);

    expect($props['locales'])->toBe(['es', 'en'])
        ->and($props['defaultLocale'])->toBe('es')
        ->and($props['version'])->toBe(1)
        ->and($props['hasUnpublishedChanges'])->toBeFalse()
        ->and(array_column($props['blocks'], 'type'))->toBe(['hero', 'event_info', 'calls', 'tracks'])
        ->and($props['blocks'][0]['title'])->toBe(['es' => 'Congreso', 'en' => 'Congress'])
        ->and($props['blocks'][0]['subtitle'])->toBe(['es' => '', 'en' => ''])
        ->and($props['preview']['event']['name'])->toBe('Congreso')
        ->and($props['preview'])->toHaveKeys(['event', 'calls', 'tracks'])
        ->and($props['publicUrl'])->toBe("/{$organization->slug}/events/{$event->slug}");
});

it('CA-030-6: the editor gets no public link while the event is draft or archived', function (EventStatus $status): void {
    [$organization, $event, $admin] = formScenario();
    $event->forceFill(['status' => $status])->save();
    editorActAsAdmin($organization, $admin);

    $html = Livewire::test(EditEventLanding::class, ['record' => $event->id])->html();
    preg_match('/data-props="([^"]*)"/', $html, $matches);
    $props = json_decode(html_entity_decode($matches[1] ?? ''), true, flags: JSON_THROW_ON_ERROR);

    expect($props)->toHaveKey('publicUrl')
        ->and($props['publicUrl'])->toBeNull();
})->with([
    'draft' => [EventStatus::Draft],
    'archived' => [EventStatus::Archived],
]);

it('CA-022-6: save draft, update and discard from the editor return a flat result and respect what each one does', function (): void {
    [$organization, $event, $admin] = formScenario();
    editorActAsAdmin($organization, $admin);
    $page = editorPage(Livewire::test(EditEventLanding::class, ['record' => $event->id]));
    $landing = fn (): EventLanding => EventLanding::query()->where('event_id', $event->id)->sole();
    $blocks = editorDraft($landing());

    $draft = $page->saveDraft([...$blocks, ['id' => 'new-1', 'type' => 'spacer', 'size' => 'lg']], 1);
    expect($draft['ok'])->toBeTrue()
        ->and($draft['version'])->toBe(2)
        ->and($draft['blocks'])->toHaveCount(5)
        ->and($draft['blocks'][4]['id'])->not->toBe('new-1')
        ->and($landing()->published_blocks)->toHaveCount(4);

    $published = $page->publish($draft['blocks'], 2);
    expect($published['ok'])->toBeTrue()
        ->and($landing()->published_blocks)->toHaveCount(5);

    $page->saveDraft($published['blocks'], $published['version']);
    $discarded = $page->discard();
    expect($discarded['ok'])->toBeTrue()->and($discarded['blocks'])->toHaveCount(5);
});

it('CA-022-9: an invalid block comes back as field errors and saves nothing', function (): void {
    [$organization, $event, $admin] = formScenario();
    editorActAsAdmin($organization, $admin);
    $page = editorPage(Livewire::test(EditEventLanding::class, ['record' => $event->id]));
    $blocks = editorDraft(EventLanding::query()->where('event_id', $event->id)->sole());
    $blocks[] = ['id' => 'new-1', 'type' => 'hero', 'title' => ['es' => ''], 'buttonUrl' => 'javascript:alert(1)'];

    $result = $page->saveDraft($blocks, 1);

    expect($result['ok'])->toBeFalse()
        ->and($result['reason'])->toBe('invalid')
        ->and($result['errors'])->toHaveKeys(['blocks.4.title.es', 'blocks.4.buttonUrl'])
        ->and(EventLanding::query()->where('event_id', $event->id)->sole()->version)->toBe(1);
});

it('CA-022-15: a stale version comes back as a conflict with the current draft', function (): void {
    [$organization, $event, $admin] = formScenario();
    editorActAsAdmin($organization, $admin);
    $page = editorPage(Livewire::test(EditEventLanding::class, ['record' => $event->id]));
    $blocks = editorDraft(EventLanding::query()->where('event_id', $event->id)->sole());

    $page->saveDraft([...$blocks, ['id' => 'new-1', 'type' => 'spacer']], 1);
    $result = $page->saveDraft([...$blocks, ['id' => 'new-2', 'type' => 'spacer', 'size' => 'lg']], 1);

    expect($result['ok'])->toBeFalse()
        ->and($result['reason'])->toBe('conflict')
        ->and($result['version'])->toBe(2)
        ->and($result['blocks'])->toHaveCount(5)
        ->and($result['blocks'][4]['size'])->toBe('md');
});

it('CA-022-31: the editor uploads an image and gets its id and an editor url; a bad file comes back as an error', function (): void {
    [$organization, $event, $admin] = formScenario();
    editorActAsAdmin($organization, $admin);
    $component = Livewire::test(EditEventLanding::class, ['record' => $event->id]);

    $component->set('imageUpload', UploadedFile::fake()->createWithContent('portada.png', editorPng()));
    $ok = editorPage($component)->uploadImage();

    $image = LandingImage::query()->where('event_id', $event->id)->sole();
    expect($ok['ok'])->toBeTrue()
        ->and($ok['image']['id'])->toBe($image->id)
        ->and($ok['image']['url'])->toBe("/admin/{$organization->slug}/events/{$event->id}/landing/images/{$image->id}");

    $component->set('imageUpload', UploadedFile::fake()->createWithContent('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'));
    $bad = editorPage($component)->uploadImage();

    expect($bad['ok'])->toBeFalse()->and($bad['errors'])->not->toBe([])
        ->and(LandingImage::query()->where('event_id', $event->id)->count())->toBe(1);
});

it('CA-022-6: without events.manage the editor methods are refused and nothing is saved', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    editorActAsAdmin($organization, $admin);
    $page = editorPage(Livewire::test(EditEventLanding::class, ['record' => $event->id]));
    $blocks = editorDraft(EventLanding::query()->where('event_id', $event->id)->sole());

    // El estado de la sesión pasa al revisor: el servidor decide con quien está autenticado.
    Auth::login($reviewer);

    expect(fn () => $page->saveDraft([...$blocks, ['id' => 'x', 'type' => 'spacer']], 1))->toThrow(AuthorizationException::class)
        ->and(fn () => $page->publish($blocks, 1))->toThrow(AuthorizationException::class)
        ->and(fn () => $page->discard())->toThrow(AuthorizationException::class);
});

it('CA-022-4: the editor image route answers 404 to another organization and 403 to a member without permission', function (): void {
    [$organization, $event, $admin] = formScenario();
    $landing = CurrentOrganization::run($organization, fn (): EventLanding => EventLanding::query()->where('event_id', $event->id)->sole());
    $path = "organizations/{$organization->id}/events/{$event->id}/media/landing/".Str::uuid().'.png';
    MediaDisk::get()->put($path, editorPng(), 'private');
    $image = CurrentOrganization::run($organization, fn (): LandingImage => LandingImage::factory()->create(['event_landing_id' => $landing->id, 'path' => $path]));
    $url = "/admin/{$organization->slug}/events/{$event->id}/landing/images/{$image->id}";

    $this->actingAs($admin)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    $this->actingAs($reviewer)->get($url)->assertForbidden();

    [$organizationB, , $adminB] = formScenario();
    $this->actingAs($adminB)->get($url)->assertNotFound();
    $this->actingAs($adminB)->get("/admin/{$organizationB->slug}/events/{$event->id}/landing/images/{$image->id}")->assertNotFound();

    $this->actingAs($admin)->get("/admin/{$organization->slug}/events/{$event->id}/landing/images/no-es-uuid")->assertNotFound();
});

it('CA-022-8: a visitor without session cannot request an editor image', function (): void {
    [$organization, $event] = formScenario();

    $this->get("/admin/{$organization->slug}/events/{$event->id}/landing/images/".Str::uuid())->assertRedirect();
});

/**
 * El borrador tal como lo ve el editor, para reenviarlo: sin los campos vacíos, que el cliente puede omitir.
 *
 * @return list<array<string, mixed>>
 */
function editorDraft(EventLanding $landing): array
{
    return array_values(array_map(
        fn (array $block): array => ['id' => $block['id'], 'type' => $block['type']] + array_filter($block, fn (mixed $value): bool => $value !== null),
        $landing->draft_blocks,
    ));
}

/** @param  Testable<EditEventLanding>  $component */
function editorPage(Testable $component): EditEventLanding
{
    $page = $component->instance();
    assert($page instanceof EditEventLanding);

    return $page;
}
