<?php

declare(strict_types=1);

use App\Actions\Events\CreateEvent;
use App\Actions\Events\RemoveEventLogo;
use App\Actions\Events\StoreEventLogo;
use App\Actions\Events\UpdateEvent;
use App\Enums\OrganizationRole;
use App\Http\Controllers\Public\ShowSubmissionConfirmationController;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\SubmissionType;
use App\Models\User;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Actions\LogActivityAction;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

/* Plan 027, F1: el logotipo del evento (acciones, ruta pública, props y aislamiento). */

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake(MediaDisk::name());
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return list<string> */
function logoFiles(): array
{
    return Storage::disk(MediaDisk::name())->allFiles();
}

function logoFile(string $name = 'logo.png'): UploadedFile
{
    return UploadedFile::fake()->image($name, 40, 40);
}

function logoUser(Organization $organization, OrganizationRole $role): User
{
    return Memberships::attach(User::factory()->create(), $organization, $role);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function logoEventData(array $overrides = []): array
{
    return array_merge([
        'name' => ['es' => 'Congreso Parques 2027'],
        'slug' => 'congreso-2027',
        'type' => 'congress',
        'starts_at' => '2027-03-10 09:00',
        'ends_at' => '2027-03-12 17:00',
        'timezone' => 'America/Mexico_City',
        'locales' => ['es'],
        'default_locale' => 'es',
        'currency' => 'MXN',
    ], $overrides);
}

/** @return array{0: Organization, 1: User, 2: Event} organización, administrador y evento sin logotipo */
function logoSetup(bool $published = false): array
{
    $organization = Memberships::organization();
    $admin = logoUser($organization, OrganizationRole::OrgAdmin);
    $event = app(CreateEvent::class)->handle($admin, $organization, logoEventData());

    if ($published) {
        $event->forceFill(['status' => 'published'])->save();
    }

    return [$organization, $admin, $event->fresh()];
}

function logoRoute(Organization $organization, Event $event): string
{
    return route('public.events.logo', ['organization' => $organization->slug, 'event' => $event->slug]);
}

it('CA-027-1: creates an event with a PNG, JPEG or WebP logo saved under its organization and event with a generated name', function (string $kind): void {
    $organization = Memberships::organization();
    $admin = logoUser($organization, OrganizationRole::OrgAdmin);
    $file = match ($kind) {
        'png' => logoFile('mi logo.php.png'),
        'jpeg' => UploadedFile::fake()->image('a.jpg', 30, 30),
        default => UploadedFile::fake()->createWithContent('a.webp', (string) base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==', true)),
    };

    $event = app(CreateEvent::class)->handle($admin, $organization, logoEventData(['logo' => $file]));

    expect($event->logo_path)->toStartWith("organizations/{$organization->id}/events/{$event->id}/media/event_logo/")
        ->and($event->logo_path)->not->toContain('mi logo')
        ->and(Storage::disk(MediaDisk::name())->exists((string) $event->logo_path))->toBeTrue()
        ->and(logoFiles())->toHaveCount(1);
})->with(['png', 'jpeg', 'webp']);

it('CA-027-2: creates an event without a logo as before', function (): void {
    [, , $event] = logoSetup();

    expect($event->logo_path)->toBeNull()->and(logoFiles())->toBe([]);
});

it('CA-027-3: rejects SVG, HTML and fake images by content and stores nothing', function (string $name, string $content): void {
    [$organization, $admin, $event] = logoSetup();
    $bad = UploadedFile::fake()->createWithContent($name, $content);

    expect(fn () => app(StoreEventLogo::class)->handle($admin, $event, $bad))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateEvent::class)->handle($admin, $event, logoEventData(['logo' => $bad])))->toThrow(ValidationException::class)
        ->and(fn () => app(CreateEvent::class)->handle($admin, $organization, logoEventData(['slug' => 'otro', 'logo' => $bad])))->toThrow(ValidationException::class);

    expect(logoFiles())->toBe([])
        ->and($event->fresh()->logo_path)->toBeNull()
        ->and(CurrentOrganization::run($organization, fn () => Event::query()->count()))->toBe(1);
})->with([
    'svg named png' => ['logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    'html named jpg' => ['logo.jpg', '<html><script>alert(1)</script></html>'],
    'php named png' => ['logo.png', '<?php system($_GET["c"]);'],
    'png header with garbage' => ['logo.png', "\x89PNG\r\n\x1a\n".str_repeat('x', 200)],
]);

it('CA-027-3: the error is reported on the logo field', function (): void {
    [, $admin, $event] = logoSetup();

    try {
        app(StoreEventLogo::class)->handle($admin, $event, UploadedFile::fake()->createWithContent('x.png', '<svg/>'));
        $this->fail('Debió rechazar el SVG.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('logo');
    }
});

it('CA-027-4: rejects more than 2 MB and accepts exactly 2 MB', function (): void {
    [, $admin, $event] = logoSetup();
    $png = UploadedFile::fake()->image('a.png', 10, 10)->getContent();
    $padded = fn (int $bytes): UploadedFile => UploadedFile::fake()->createWithContent('a.png', $png.str_repeat("\0", $bytes - strlen($png)));

    app(StoreEventLogo::class)->handle($admin, $event, $padded(2 * 1024 * 1024));
    $stored = logoFiles();

    expect($stored)->toHaveCount(1)
        ->and(fn () => app(StoreEventLogo::class)->handle($admin, $event->fresh(), $padded(2 * 1024 * 1024 + 1)))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateEvent::class)->handle($admin, $event->fresh(), logoEventData(['logo' => $padded(2 * 1024 * 1024 + 1024)])))->toThrow(ValidationException::class)
        ->and(logoFiles())->toBe($stored);
});

it('CA-027-5: a new logo deletes the previous file in the same operation', function (): void {
    [, $admin, $event] = logoSetup();

    $first = app(StoreEventLogo::class)->handle($admin, $event, logoFile())->logo_path;
    $second = app(UpdateEvent::class)->handle($admin, $event->fresh(), logoEventData(['logo' => logoFile('b.png')]))->logo_path;

    expect($second)->not->toBe($first)
        ->and(logoFiles())->toBe([$second])
        ->and($event->fresh()->logo_path)->toBe($second);

    $entry = Activity::query()->where('event', 'event_logo_changed')->latest('id')->firstOrFail();
    expect($entry->subject_id)->toBe($event->id)->and($entry->causer_id)->toBe($admin->id)
        ->and(json_encode($entry->properties))->not->toContain('event_logo/');
});

it('CA-027-6: removing the logo empties the column, deletes the file and audits it', function (): void {
    [, $admin, $event] = logoSetup();
    app(StoreEventLogo::class)->handle($admin, $event, logoFile());

    app(RemoveEventLogo::class)->handle($admin, $event->fresh());

    expect($event->fresh()->logo_path)->toBeNull()
        ->and(logoFiles())->toBe([])
        ->and(Activity::query()->where('event', 'event_logo_removed')->sole()->subject_id)->toBe($event->id);

    // Quitarlo de nuevo no falla ni audita otra vez.
    app(RemoveEventLogo::class)->handle($admin, $event->fresh());
    expect(Activity::query()->where('event', 'event_logo_removed')->count())->toBe(1);
});

it('CA-027-6: UpdateEvent with remove_logo removes it and a new logo wins over remove_logo', function (): void {
    [, $admin, $event] = logoSetup();
    app(StoreEventLogo::class)->handle($admin, $event, logoFile());

    app(UpdateEvent::class)->handle($admin, $event->fresh(), logoEventData(['remove_logo' => true]));
    expect($event->fresh()->logo_path)->toBeNull()->and(logoFiles())->toBe([]);

    $kept = app(UpdateEvent::class)->handle($admin, $event->fresh(), logoEventData(['remove_logo' => true, 'logo' => logoFile()]));
    expect($kept->logo_path)->not->toBeNull()->and(logoFiles())->toHaveCount(1);
});

it('CA-027-7: a failure while changing the logo leaves no orphan file and keeps the previous one', function (): void {
    [, $admin, $event] = logoSetup();
    $previous = app(StoreEventLogo::class)->handle($admin, $event, logoFile())->logo_path;
    $state = new stdClass;
    $state->fail = true;
    Event::updated(function () use ($state): void {
        if ($state->fail) {
            throw new RuntimeException('falla simulada');
        }
    });

    try {
        expect(fn () => app(StoreEventLogo::class)->handle($admin, $event->fresh(), logoFile('b.png')))->toThrow(RuntimeException::class)
            ->and(fn () => app(UpdateEvent::class)->handle($admin, $event->fresh(), logoEventData(['logo' => logoFile('c.png')])))->toThrow(RuntimeException::class);

        expect(logoFiles())->toBe([$previous])
            ->and($event->fresh()->logo_path)->toBe($previous);
    } finally {
        $state->fail = false;
    }
});

it('CA-027-7: a failure while creating the event with a logo leaves no event and no file', function (): void {
    $organization = Memberships::organization();
    $admin = logoUser($organization, OrganizationRole::OrgAdmin);
    $state = new stdClass;
    $state->fail = true;
    Event::updated(function () use ($state): void {
        if ($state->fail) {
            throw new RuntimeException('falla simulada');
        }
    });

    try {
        expect(fn () => app(CreateEvent::class)->handle($admin, $organization, logoEventData(['logo' => logoFile()])))->toThrow(RuntimeException::class);

        expect(logoFiles())->toBe([])
            ->and(CurrentOrganization::run($organization, fn () => Event::query()->count()))->toBe(0);
    } finally {
        $state->fail = false;
    }
});

it('CA-027-8: a reviewer cannot store, change or remove the logo and nothing changes', function (): void {
    [$organization, , $event] = logoSetup();
    $organizer = logoUser($organization, OrganizationRole::Organizer);
    app(StoreEventLogo::class)->handle($organizer, $event, logoFile());
    $path = $event->fresh()->logo_path;
    $reviewer = logoUser($organization, OrganizationRole::Reviewer);

    expect(fn () => app(StoreEventLogo::class)->handle($reviewer, $event->fresh(), logoFile()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RemoveEventLogo::class)->handle($reviewer, $event->fresh()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateEvent::class)->handle($reviewer, $event->fresh(), logoEventData(['logo' => logoFile()])))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CreateEvent::class)->handle($reviewer, $organization, logoEventData(['slug' => 'x', 'logo' => logoFile()])))->toThrow(AuthorizationException::class);

    expect($event->fresh()->logo_path)->toBe($path)->and(logoFiles())->toBe([$path]);
});

it('CA-027-8: an organizer and an admin can change the logo', function (OrganizationRole $role): void {
    [$organization, , $event] = logoSetup();
    $user = logoUser($organization, $role);

    expect(app(StoreEventLogo::class)->handle($user, $event, logoFile())->logo_path)->not->toBeNull();
})->with([OrganizationRole::Organizer, OrganizationRole::OrgAdmin]);

it('CA-027-9: an admin of another organization cannot store or remove the logo of the event', function (): void {
    [, , $eventOfB] = logoSetup();
    $organizationA = Memberships::organization();
    $adminA = logoUser($organizationA, OrganizationRole::OrgAdmin);

    expect(fn () => app(StoreEventLogo::class)->handle($adminA, $eventOfB, logoFile()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RemoveEventLogo::class)->handle($adminA, $eventOfB))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateEvent::class)->handle($adminA, $eventOfB, logoEventData(['logo' => logoFile()])))->toThrow(AuthorizationException::class);

    expect($eventOfB->fresh()->logo_path)->toBeNull()->and(logoFiles())->toBe([]);
});

it('CA-027-9: the panel preview route answers 404 for another organization and 403 for a reviewer', function (): void {
    [$organizationB, $adminB, $eventOfB] = logoSetup();
    app(StoreEventLogo::class)->handle($adminB, $eventOfB, logoFile());
    $adminA = logoUser(Memberships::organization(), OrganizationRole::OrgAdmin);
    $reviewer = logoUser($organizationB, OrganizationRole::Reviewer);
    $url = route('event-logo.show', ['organization' => $organizationB->slug, 'event' => $eventOfB->id]);

    $this->get($url)->assertRedirect();
    $this->actingAs($adminA)->get($url)->assertNotFound();
    $this->actingAs($reviewer)->get($url)->assertForbidden();
});

it('CA-027-9: the panel preview shows the logo of a draft event to its admin without shared cache', function (): void {
    [$organization, $admin, $event] = logoSetup();
    app(StoreEventLogo::class)->handle($admin, $event, logoFile());

    $this->actingAs($admin)->get(route('event-logo.show', ['organization' => $organization->slug, 'event' => $event->id]))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('CA-027-9: the public route of another organization slug answers 404', function (): void {
    [$organizationB, $adminB, $eventOfB] = logoSetup(published: true);
    app(StoreEventLogo::class)->handle($adminB, $eventOfB, logoFile());
    $organizationA = Memberships::organization();

    $this->get("/{$organizationA->slug}/events/{$eventOfB->slug}/logo")->assertNotFound();
    $this->get(logoRoute($organizationB, $eventOfB))->assertOk();
});

it('CA-027-10: a visitor gets the logo of a published event with nosniff and short cache', function (): void {
    [$organization, $admin, $event] = logoSetup(published: true);
    app(StoreEventLogo::class)->handle($admin, $event, logoFile());

    $this->get(logoRoute($organization, $event))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Cache-Control', 'max-age=300, public');
});

it('CA-027-11: draft, archived, without logo or missing file all answer the same 404', function (): void {
    [$organization, $admin, $event] = logoSetup();
    app(StoreEventLogo::class)->handle($admin, $event, logoFile());
    $url = logoRoute($organization, $event);

    $responses = [];

    // Borrador.
    $responses[] = $this->get($url);

    // Archivado.
    $event->forceFill(['status' => 'archived'])->save();
    $responses[] = $this->get($url);

    // Publicado sin logotipo.
    $event->forceFill(['status' => 'published', 'logo_path' => null])->save();
    $responses[] = $this->get($url);

    // Publicado con logotipo cuyo archivo falta.
    $event->forceFill(['logo_path' => "organizations/{$organization->id}/events/{$event->id}/media/event_logo/no-existe.png"])->save();
    $responses[] = $this->get($url);

    foreach ($responses as $response) {
        $response->assertNotFound();
    }

    expect(array_unique(array_map(fn ($r): string => (string) preg_replace('/\s+/', ' ', strip_tags((string) $r->getContent())), $responses)))->toHaveCount(1);
});

it('CA-027-12: the event page carries logoUrl as a public address or null and never the disk path', function (): void {
    [$organization, $admin, $event] = logoSetup(published: true);
    $page = fn () => $this->get("/{$organization->slug}/events/{$event->slug}");

    $page()->assertInertia(fn (Assert $p) => $p->where('event.logoUrl', null));

    app(StoreEventLogo::class)->handle($admin, $event, logoFile());
    $path = (string) $event->fresh()->logo_path;

    $page()->assertInertia(fn (Assert $p) => $p
        ->where('event.logoUrl', fn (mixed $url): bool => is_string($url) && str_starts_with($url, "/{$organization->slug}/events/{$event->slug}/logo")));
    expect($page()->getContent())->not->toContain($path)->and($page()->getContent())->not->toContain('event_logo/');
});

it('CA-027-12: the call and thank-you pages carry logoUrl without the disk path', function (): void {
    [$organization, $admin, $event] = logoSetup(published: true);
    app(StoreEventLogo::class)->handle($admin, $event, logoFile());
    $call = CurrentOrganization::run($organization, function () use ($organization, $event): CallForSubmissions {
        $type = SubmissionType::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);

        return CallForSubmissions::factory()->published()->create([
            'organization_id' => $organization->id,
            'event_id' => $event->id,
            'submission_type_id' => $type->id,
            'slug' => 'ponencias',
            'locales' => ['es'],
            'opens_at' => '2027-01-10 15:00:00',
            'closes_at' => '2027-02-15 23:00:00',
        ]);
    });
    $base = "/{$organization->slug}/events/{$event->slug}/call/{$call->slug}";
    $expected = fn (mixed $url): bool => is_string($url) && str_starts_with($url, "/{$organization->slug}/events/{$event->slug}/logo");

    $this->get($base)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Calls/Show')->where('event.logoUrl', $expected));
    $this->withSession([ShowSubmissionConfirmationController::sessionKey($call) => true])
        ->get("{$base}/submitted")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Calls/Submitted')->where('event.logoUrl', $expected));
});

it('CA-027-7: a failure after the logo was saved (audit of the event) removes the new file and rolls back', function (): void {
    [$organization, $admin, $event] = logoSetup();
    $previous = app(StoreEventLogo::class)->handle($admin, $event, logoFile())->logo_path;
    $state = new stdClass;
    $state->fail = true;
    LogActivityAction::beforeLogging(function (ActivityContract $activity) use ($state): void {
        if ($state->fail && $activity instanceof Model && in_array($activity->getAttribute('event'), ['event_updated', 'event_created'], true)) {
            throw new RuntimeException('falla simulada');
        }
    });

    try {
        expect(fn () => app(UpdateEvent::class)->handle($admin, $event->fresh(), logoEventData(['logo' => logoFile('b.png')])))->toThrow(RuntimeException::class)
            ->and(fn () => app(CreateEvent::class)->handle($admin, $organization, logoEventData(['slug' => 'nuevo', 'logo' => logoFile('c.png')])))->toThrow(RuntimeException::class);

        expect(logoFiles())->toBe([$previous])
            ->and($event->fresh()->logo_path)->toBe($previous)
            ->and(CurrentOrganization::run($organization, fn () => Event::query()->count()))->toBe(1);
    } finally {
        $state->fail = false;
        LogActivityAction::clearBeforeLoggingCallbacks();
    }
});
