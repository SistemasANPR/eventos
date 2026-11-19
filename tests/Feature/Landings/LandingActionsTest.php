<?php

declare(strict_types=1);

use App\Actions\Events\CreateEvent;
use App\Actions\Landings\CreateEventLanding;
use App\Actions\Landings\DeleteLandingImage;
use App\Actions\Landings\DiscardLandingDraft;
use App\Actions\Landings\PublishEventLanding;
use App\Actions\Landings\SaveLandingDraft;
use App\Actions\Landings\UploadLandingImage;
use App\Enums\OrganizationRole;
use App\Exceptions\Domain\LandingVersionConflict;
use App\Models\Event;
use App\Models\EventLanding;
use App\Models\LandingImage;
use App\Models\Organization;
use App\Models\User;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake(MediaDisk::name());
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Una organización con un administrador, un evento y su landing publicada con la plantilla.
 *
 * @return array{0: Organization, 1: User, 2: Event, 3: EventLanding}
 */
function landingFixture(OrganizationRole $role = OrganizationRole::OrgAdmin, bool $publish = true): array
{
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);
    $event = Event::factory()->create(['organization_id' => $organization->id, 'locales' => ['es', 'en'], 'default_locale' => 'es']);
    $landing = app(CreateEventLanding::class)->handle($user, $event, publish: $publish);

    return [$organization, $user, $event, $landing];
}

/** @return list<array<string, mixed>> un bloque de texto con un párrafo, para tener algo distinto de la plantilla */
function landingAddedText(EventLanding $landing, string $text = 'Hola'): array
{
    return [...$landing->draft_blocks, ['type' => 'rich_text', 'html' => ['es' => "<p>{$text}</p>"]]];
}

function landingPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
}

function landingUpload(string $name = 'portada.png', ?string $content = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content ?? landingPng());
}

/** @return list<string> */
function landingStoredFiles(): array
{
    return Storage::disk(MediaDisk::name())->allFiles();
}

it('CA-022-1: creating an event creates its landing with the template in draft and published, in the same transaction', function (): void {
    $organization = Memberships::organization();
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);

    $event = app(CreateEvent::class)->handle($organizer, $organization, [
        'name' => ['es' => 'Congreso Parques 2027', 'en' => 'Parks Congress 2027'],
        'slug' => 'congreso-landing',
        'type' => 'congress',
        'starts_at' => '2027-03-10 09:00',
        'ends_at' => '2027-03-12 17:00',
        'timezone' => 'America/Mexico_City',
        'locales' => ['es', 'en'],
        'default_locale' => 'es',
        'currency' => 'MXN',
    ]);

    $landing = CurrentOrganization::run($organization, fn (): EventLanding => EventLanding::query()->where('event_id', $event->id)->sole());

    expect(array_column($landing->draft_blocks, 'type'))->toBe(['hero', 'event_info', 'calls', 'tracks'])
        ->and($landing->published_blocks)->toEqual($landing->draft_blocks)
        ->and($landing->published_at)->not->toBeNull()
        ->and($landing->published_by)->toBe($organizer->id)
        ->and($landing->version)->toBe(1)
        ->and($landing->draft_blocks[0]['title']['es'])->toBe('Congreso Parques 2027')
        ->and(Activity::query()->where('event', 'landing_created')->count())->toBe(1);
});

it('CA-022-1: if the landing fails the event is not left half created', function (): void {
    $organization = Memberships::organization();
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    $armed = new stdClass;
    $armed->on = true;
    EventLanding::creating(function () use ($armed): void {
        if (! $armed->on) {
            return;
        }

        throw new RuntimeException('fallo al crear la landing');
    });

    expect(fn () => app(CreateEvent::class)->handle($organizer, $organization, [
        'name' => ['es' => 'Congreso', 'en' => 'Congress'],
        'slug' => 'congreso-atomico',
        'type' => 'congress',
        'starts_at' => '2027-03-10 09:00',
        'ends_at' => '2027-03-12 17:00',
        'timezone' => 'America/Mexico_City',
        'locales' => ['es', 'en'],
        'default_locale' => 'es',
        'currency' => 'MXN',
    ]))->toThrow(RuntimeException::class);

    $armed->on = false;
    expect(Event::query()->withoutGlobalScopes()->where('slug', 'congreso-atomico')->exists())->toBeFalse()
        ->and(EventLanding::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('CA-022-2: an existing event gets its landing with the template in draft only, and creating it twice changes nothing', function (): void {
    [, $user, $event, $landing] = landingFixture(publish: false);

    expect($landing->published_blocks)->toBeNull()
        ->and($landing->published_at)->toBeNull()
        ->and(array_column($landing->draft_blocks, 'type'))->toBe(['hero', 'event_info', 'calls', 'tracks']);

    $again = app(CreateEventLanding::class)->handle($user, $event);

    expect($again->id)->toBe($landing->id)
        ->and(EventLanding::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'landing_created')->count())->toBe(1);
});

it('CA-022-6: org_admin and organizer save the draft, update and discard', function (OrganizationRole $role): void {
    [, $user, , $landing] = landingFixture($role);

    $saved = app(SaveLandingDraft::class)->handle($user, $landing, landingAddedText($landing), $landing->version);
    expect($saved->version)->toBe(2)->and($saved->draft_blocks)->toHaveCount(5);

    $published = app(PublishEventLanding::class)->handle($user, $saved);
    expect($published->published_blocks)->toHaveCount(5);

    $edited = app(SaveLandingDraft::class)->handle($user, $published, [...$published->draft_blocks, ['type' => 'spacer']], $published->version);
    $discarded = app(DiscardLandingDraft::class)->handle($user, $edited, $edited->version);

    expect($discarded->draft_blocks)->toHaveCount(5);
})->with([
    'org_admin' => [OrganizationRole::OrgAdmin],
    'organizer' => [OrganizationRole::Organizer],
]);

it('CA-022-6: without events.manage the operations are refused and nothing is saved', function (?OrganizationRole $role): void {
    [$organization, , , $landing] = landingFixture();
    $outsider = Memberships::attach(User::factory()->create(), $organization, $role);
    $before = $landing->draft_blocks;

    foreach ([
        fn () => app(SaveLandingDraft::class)->handle($outsider, $landing, landingAddedText($landing), $landing->version),
        fn () => app(PublishEventLanding::class)->handle($outsider, $landing, landingAddedText($landing), $landing->version),
        fn () => app(DiscardLandingDraft::class)->handle($outsider, $landing, $landing->version),
        fn () => app(UploadLandingImage::class)->handle($outsider, $landing, landingUpload()),
        fn () => app(CreateEventLanding::class)->handle($outsider, Event::query()->withoutGlobalScopes()->findOrFail($landing->event_id)),
    ] as $attempt) {
        expect($attempt)->toThrow(AuthorizationException::class);
    }

    $fresh = CurrentOrganization::run($organization, fn (): EventLanding => EventLanding::query()->findOrFail($landing->id));
    expect($fresh->draft_blocks)->toEqual($before)
        ->and($fresh->version)->toBe(1)
        ->and(landingStoredFiles())->toBe([]);
})->with([
    'reviewer' => [OrganizationRole::Reviewer],
    'no role' => [null],
]);

it('CA-022-7: a user of another organization cannot call the actions on this landing', function (): void {
    [, , , $landing] = landingFixture();
    $stranger = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::OrgAdmin);

    expect(fn () => app(SaveLandingDraft::class)->handle($stranger, $landing, landingAddedText($landing), 1))->toThrow(AuthorizationException::class)
        ->and(fn () => app(PublishEventLanding::class)->handle($stranger, $landing))->toThrow(AuthorizationException::class);
});

it('CA-022-9: an invalid block saves none and leaves the draft as it was', function (): void {
    [$organization, $user, , $landing] = landingFixture();

    expect(fn () => app(SaveLandingDraft::class)->handle($user, $landing, [...$landing->draft_blocks, ['type' => 'iframe']], 1))
        ->toThrow(ValidationException::class);

    $fresh = CurrentOrganization::run($organization, fn (): EventLanding => EventLanding::query()->findOrFail($landing->id));
    expect($fresh->draft_blocks)->toEqual($landing->draft_blocks)->and($fresh->version)->toBe(1);
});

it('CA-022-14: the ids come from the server: a forged one is replaced, a known one is kept', function (): void {
    [, $user, , $landing] = landingFixture();
    $known = $landing->draft_blocks[0]['id'];
    $forged = (string) Str::uuid();

    $saved = app(SaveLandingDraft::class)->handle($user, $landing, [
        [...$landing->draft_blocks[0], 'id' => $known],
        ['type' => 'spacer', 'id' => $forged],
    ], 1);

    expect($saved->draft_blocks[0]['id'])->toBe($known)
        ->and($saved->draft_blocks[1]['id'])->not->toBe($forged);
});

it('CA-022-15: a second save from the same starting version is rejected and does not overwrite the first', function (): void {
    [$organization, $user, , $landing] = landingFixture();

    app(SaveLandingDraft::class)->handle($user, $landing, landingAddedText($landing, 'Primero'), 1);

    expect(fn () => app(SaveLandingDraft::class)->handle($user, $landing, landingAddedText($landing, 'Segundo'), 1))
        ->toThrow(LandingVersionConflict::class)
        ->and(fn () => app(PublishEventLanding::class)->handle($user, $landing, landingAddedText($landing, 'Segundo'), 1))
        ->toThrow(LandingVersionConflict::class);

    $fresh = CurrentOrganization::run($organization, fn (): EventLanding => EventLanding::query()->findOrFail($landing->id));
    expect($fresh->draft_blocks[4]['html']['es'])->toBe('<p>Primero</p>')->and($fresh->version)->toBe(2);
});

it('CA-022-16: updating copies the draft to the published version, raises the version and audits without content', function (): void {
    [, $user, , $landing] = landingFixture();

    $published = app(PublishEventLanding::class)->handle($user, $landing, landingAddedText($landing, 'Nuevo'), 1);

    expect($published->published_blocks)->toEqual($published->draft_blocks)
        ->and($published->published_blocks)->toHaveCount(5)
        ->and($published->version)->toBeGreaterThan(1)
        ->and($published->published_by)->toBe($user->id)
        ->and($published->published_at)->not->toBeNull();

    $entry = Activity::query()->where('event', 'landing_published')->sole();
    expect($entry->causer_id)->toBe($user->id)
        ->and($entry->properties->keys()->sort()->values()->all())->toBe(['event_id', 'landing_id', 'organization_id', 'version'])
        ->and(json_encode($entry->properties))->not->toContain('Nuevo');
});

it('CA-022-17: saving a draft does not change what is published', function (): void {
    [, $user, , $landing] = landingFixture();
    $published = $landing->published_blocks;

    $saved = app(SaveLandingDraft::class)->handle($user, $landing, landingAddedText($landing), 1);

    expect($saved->draft_blocks)->toHaveCount(5)
        ->and($saved->published_blocks)->toEqual($published)
        ->and($saved->hasUnpublishedChanges())->toBeTrue();
});

it('CA-022-18: discarding returns the draft to the published version and audits it', function (): void {
    [, $user, , $landing] = landingFixture();
    $saved = app(SaveLandingDraft::class)->handle($user, $landing, landingAddedText($landing), 1);

    $discarded = app(DiscardLandingDraft::class)->handle($user, $saved, $saved->version);

    expect($discarded->draft_blocks)->toEqual($landing->published_blocks)
        ->and($discarded->hasUnpublishedChanges())->toBeFalse()
        ->and(Activity::query()->where('event', 'landing_draft_discarded')->count())->toBe(1);

    // Sin cambios que descartar no hay nada que hacer.
    $again = app(DiscardLandingDraft::class)->handle($user, $discarded, $discarded->version);
    expect($again->version)->toBe($discarded->version)
        ->and(Activity::query()->where('event', 'landing_draft_discarded')->count())->toBe(1);
});

it('CA-022-18: a landing that was never published has nothing to go back to', function (): void {
    [, $user, , $landing] = landingFixture(publish: false);

    expect(fn () => app(DiscardLandingDraft::class)->handle($user, $landing, 1))->toThrow(ValidationException::class);
});

it('CA-022-19: updating again without changes does not raise the version nor duplicate the audit', function (): void {
    [, $user, , $landing] = landingFixture();
    $first = app(PublishEventLanding::class)->handle($user, $landing, landingAddedText($landing), 1);

    $second = app(PublishEventLanding::class)->handle($user, $first);
    $third = app(PublishEventLanding::class)->handle($user, $second, $second->draft_blocks, $second->version);

    expect($second->version)->toBe($first->version)
        ->and($third->version)->toBe($first->version)
        ->and(Activity::query()->where('event', 'landing_published')->count())->toBe(1);
});

it('CA-022-20: a failure halfway through updating rolls back and keeps the previous published version', function (): void {
    [$organization, $user, , $landing] = landingFixture();
    $image = CurrentOrganization::run($organization, fn (): LandingImage => LandingImage::factory()->create(['event_landing_id' => $landing->id]));
    $before = $landing->published_blocks;

    // La limpieza de imágenes sin uso ocurre tras copiar el borrador: si falla ahí, todo se deshace.
    $armed = new stdClass;
    $armed->on = true;
    LandingImage::deleting(function () use ($armed): void {
        if (! $armed->on) {
            return;
        }

        throw new RuntimeException('fallo a mitad de actualizar');
    });

    expect(fn () => app(PublishEventLanding::class)->handle($user, $landing, landingAddedText($landing, 'No debe quedar'), 1))
        ->toThrow(RuntimeException::class);

    $armed->on = false;
    $fresh = CurrentOrganization::run($organization, fn (): EventLanding => EventLanding::query()->findOrFail($landing->id));

    expect($fresh->published_blocks)->toEqual($before)
        ->and($fresh->draft_blocks)->toEqual($landing->draft_blocks)
        ->and($fresh->version)->toBe(1)
        ->and(Activity::query()->where('event', 'landing_published')->count())->toBe(0)
        ->and(CurrentOrganization::run($organization, fn (): bool => LandingImage::query()->whereKey($image->id)->exists()))->toBeTrue();
});

it('CA-022-31: an uploaded image gets a generated name under the organization and event and the blocks keep its id', function (): void {
    [$organization, $user, $event, $landing] = landingFixture();

    $image = app(UploadLandingImage::class)->handle($user, $landing, landingUpload('mi foto.php.png'));

    expect($image->path)->toStartWith("organizations/{$organization->id}/events/{$event->id}/media/landing/")
        ->and($image->path)->toMatch('/\/[0-9a-f-]{36}\.png\z/')
        ->and($image->mime)->toBe('image/png')
        ->and([$image->width, $image->height])->toBe([1, 1])
        ->and($image->event_landing_id)->toBe($landing->id)
        ->and(landingStoredFiles())->toBe([$image->path])
        ->and(Activity::query()->where('event', 'landing_image_uploaded')->count())->toBe(1);

    $blocks = [...$landing->draft_blocks, ['type' => 'image_gallery', 'images' => [['image' => ['id' => $image->id, 'url' => '/x'], 'alt' => ['es' => 'Portada']]]]];
    $saved = app(SaveLandingDraft::class)->handle($user, $landing, $blocks, 1);

    expect($saved->draft_blocks[4]['images'][0]['image'])->toBe(['id' => $image->id])
        ->and(json_encode($saved->draft_blocks))->not->toContain($image->path);
});

it('CA-022-29: a file that is not JPEG, PNG or WebP by content is rejected and leaves nothing on the disk', function (string $name, string $content): void {
    [, $user, , $landing] = landingFixture();

    expect(fn () => app(UploadLandingImage::class)->handle($user, $landing, landingUpload($name, $content)))->toThrow(ValidationException::class)
        ->and(landingStoredFiles())->toBe([])
        ->and(LandingImage::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'svg named png' => ['logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    'html named jpg' => ['foto.jpg', '<html><script>alert(1)</script></html>'],
    'executable' => ['foto.png', "MZ\x90\x00\x03\x00\x00\x00"],
    'png with php' => ['foto.png', "\x89PNG\r\n\x1a\n".'<?php system($_GET["c"]); ?>'],
]);

it('CA-022-30: an image above 5 MB is rejected', function (): void {
    [, $user, , $landing] = landingFixture();
    $large = landingPng().str_repeat("\0", 5 * 1024 * 1024);

    expect(fn () => app(UploadLandingImage::class)->handle($user, $landing, landingUpload('grande.png', $large)))->toThrow(ValidationException::class)
        ->and(landingStoredFiles())->toBe([]);
});

it('CA-022-10: a landing admits at most 20 images', function (): void {
    [$organization, $user, , $landing] = landingFixture();
    CurrentOrganization::run($organization, fn () => LandingImage::factory()->count(20)->create(['event_landing_id' => $landing->id]));

    expect(fn () => app(UploadLandingImage::class)->handle($user, $landing, landingUpload()))->toThrow(ValidationException::class)
        ->and(landingStoredFiles())->toBe([]);
});

it('CA-022-4: blocks cannot reference an image of another landing', function (): void {
    [, $user, , $landing] = landingFixture();
    [$otherOrganization, , , $otherLanding] = landingFixture();
    $foreign = CurrentOrganization::run($otherOrganization, fn (): LandingImage => LandingImage::factory()->create(['event_landing_id' => $otherLanding->id]));

    $blocks = [...$landing->draft_blocks, ['type' => 'image_gallery', 'images' => [['image' => ['id' => $foreign->id], 'alt' => ['es' => 'Ajena']]]]];

    expect(fn () => app(SaveLandingDraft::class)->handle($user, $landing, $blocks, 1))->toThrow(ValidationException::class);
});

it('CA-022-33: an image removed from the blocks is marked deleted and its file removed when updating', function (): void {
    [$organization, $user, , $landing] = landingFixture();
    CurrentOrganization::set($organization);
    $kept = app(UploadLandingImage::class)->handle($user, $landing, landingUpload());
    $removed = app(UploadLandingImage::class)->handle($user, $landing, landingUpload());
    $gallery = fn (array $images): array => ['type' => 'image_gallery', 'images' => array_map(fn (LandingImage $image): array => ['image' => ['id' => $image->id], 'alt' => ['es' => 'Foto']], $images)];

    $first = app(PublishEventLanding::class)->handle($user, $landing, [...$landing->draft_blocks, $gallery([$kept, $removed])], 1);
    expect(landingStoredFiles())->toHaveCount(2);

    $second = app(PublishEventLanding::class)->handle($user, $first, [...$landing->draft_blocks, $gallery([$kept])], $first->version);

    expect($second->publishedImageIds())->toBe([$kept->id])
        ->and(LandingImage::query()->whereKey($removed->id)->exists())->toBeFalse()
        ->and(LandingImage::query()->withTrashed()->whereKey($removed->id)->exists())->toBeTrue()
        ->and(landingStoredFiles())->toBe([$kept->path]);
});

it('CA-022-33: an image the published version still uses is not deleted', function (): void {
    [$organization, $user, , $landing] = landingFixture();
    CurrentOrganization::set($organization);
    $image = app(UploadLandingImage::class)->handle($user, $landing, landingUpload());
    $published = app(PublishEventLanding::class)->handle($user, $landing, [...$landing->draft_blocks, ['type' => 'image_gallery', 'images' => [['image' => ['id' => $image->id], 'alt' => ['es' => 'Foto']]]]], 1);

    expect(fn () => app(DeleteLandingImage::class)->handle($user, $image))->toThrow(ValidationException::class)
        ->and(landingStoredFiles())->toBe([$image->path]);

    // Quitada de los bloques y actualizada, ya se puede (y la propia actualización la limpió).
    app(PublishEventLanding::class)->handle($user, $published, $landing->draft_blocks, $published->version);
    expect(landingStoredFiles())->toBe([]);
});

it('CA-022-33: deleting an image that no block uses removes the row and the file and audits it', function (): void {
    [$organization, $user, , $landing] = landingFixture();
    CurrentOrganization::set($organization);
    $image = app(UploadLandingImage::class)->handle($user, $landing, landingUpload());

    app(DeleteLandingImage::class)->handle($user, $image);

    expect(LandingImage::query()->count())->toBe(0)
        ->and(landingStoredFiles())->toBe([])
        ->and(Activity::query()->where('event', 'landing_image_deleted')->count())->toBe(1);
});
