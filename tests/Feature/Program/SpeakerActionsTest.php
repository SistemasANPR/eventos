<?php

declare(strict_types=1);

use App\Actions\Program\CreateSpeaker;
use App\Actions\Program\DeleteSpeaker;
use App\Actions\Program\SetSpeakerPublication;
use App\Actions\Program\UpdateSpeaker;
use App\Enums\OrganizationRole;
use App\Models\Event;
use App\Models\Speaker;
use App\Models\SpeakerCategory;
use App\Models\User;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

/** @return array{0: Event, 1: User} evento de una organización nueva y un usuario con el rol indicado en ella */
function speakerScenario(OrganizationRole $role = OrganizationRole::OrgAdmin): array
{
    CurrentOrganization::forget();
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    // Las pruebas consultan ponentes directo: el contexto es el de la última organización creada.
    CurrentOrganization::set($organization);

    return [$event, $user];
}

/** @param  array<string, mixed>  $data */
function createSpeaker(User $actor, Event $event, array $data = []): Speaker
{
    return app(CreateSpeaker::class)->handle($actor, $event, ['first_name' => 'Ada', 'last_name' => 'Lovelace', ...$data]);
}

/** @return list<string> archivos del disco de imágenes */
function mediaFiles(): array
{
    return Storage::disk(MediaDisk::name())->allFiles();
}

/** @return array<string, list<string>> errores de validación de la operación */
function validationErrors(Closure $operation): array
{
    try {
        $operation();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

function speakerCategory(Event $event, string $name = 'Invitado'): SpeakerCategory
{
    return SpeakerCategory::factory()->create(['organization_id' => $event->organization_id, 'event_id' => $event->id, 'name' => $name]);
}

it('CA-021-1: creates a published speaker in the event with only first and last name', function (): void {
    [$event, $admin] = speakerScenario();

    $speaker = createSpeaker($admin, $event);

    expect($speaker->event_id)->toBe($event->id)
        ->and($speaker->organization_id)->toBe($event->organization_id)
        ->and($speaker->is_published)->toBeTrue()
        ->and($speaker->full_name)->toBe('Ada Lovelace')
        ->and($speaker->email)->toBeNull()
        ->and($speaker->social_links)->toBe([])
        ->and($speaker->hidden_by_reversal_at)->toBeNull();
});

it('CA-021-1: first and last name are required and cannot be blank', function (array $data, string $field): void {
    [$event, $admin] = speakerScenario();

    expect(validationErrors(fn () => app(CreateSpeaker::class)->handle($admin, $event, $data)))->toHaveKey($field)
        ->and(Speaker::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'no last name' => [['first_name' => 'Ada'], 'last_name'],
    'no first name' => [['last_name' => 'Lovelace'], 'first_name'],
    'blank last name' => [['first_name' => 'Ada', 'last_name' => '   '], 'last_name'],
    'blank first name' => [['first_name' => '', 'last_name' => 'Lovelace'], 'first_name'],
    'too long' => [['first_name' => str_repeat('a', 101), 'last_name' => 'Lovelace'], 'first_name'],
]);

it('CA-021-1: the email is unique in the event without regard to case, and a deleted speaker frees it', function (): void {
    [$event, $admin] = speakerScenario();
    $other = Event::factory()->create(['organization_id' => $event->organization_id]);
    $first = createSpeaker($admin, $event, ['email' => 'Ada@Example.com']);

    foreach (['ada@example.com', 'ADA@EXAMPLE.COM', ' ada@example.com '] as $email) {
        expect(validationErrors(fn () => createSpeaker($admin, $event, ['email' => $email])))->toHaveKey('email');
    }

    // Otro evento: la ficha es del evento, no de la persona.
    createSpeaker($admin, $other, ['email' => 'ada@example.com']);
    // Sin correo no choca con nadie.
    createSpeaker($admin, $event, ['first_name' => 'Sin', 'email' => '']);
    createSpeaker($admin, $event, ['first_name' => 'Otro', 'email' => null]);

    app(DeleteSpeaker::class)->handle($admin, $first);
    $again = createSpeaker($admin, $event, ['email' => 'ada@example.com']);

    expect($again->email)->toBe('ada@example.com');
});

it('CA-021-1: editing keeps the rule and a speaker does not clash with its own email', function (): void {
    [$event, $admin] = speakerScenario();
    $first = createSpeaker($admin, $event, ['email' => 'ada@example.com']);
    $second = createSpeaker($admin, $event, ['first_name' => 'Grace', 'email' => 'grace@example.com']);

    expect(validationErrors(fn () => app(UpdateSpeaker::class)->handle($admin, $second, ['email' => 'ADA@example.com'])))->toHaveKey('email')
        ->and(app(UpdateSpeaker::class)->handle($admin, $first, ['email' => 'ADA@example.com', 'company' => 'Analytical'])->email)->toBe('ADA@example.com');
});

it('CA-021-2: keeps the biography, tagline, company, job title, country and social links', function (): void {
    [$event, $admin] = speakerScenario();

    $speaker = createSpeaker($admin, $event, [
        'bio' => '<p>Pionera de la <strong>computación</strong>.</p>',
        'tagline' => 'Escribió el primer programa',
        'company' => 'Analytical Engines',
        'job_title' => 'Matemática',
        'country' => 'gb',
        'social_links' => [
            ['network' => 'linkedin', 'url' => 'https://www.linkedin.com/in/ada'],
            ['network' => 'x', 'url' => 'https://twitter.com/ada'],
            ['network' => 'website', 'url' => 'https://ada.example.com/about'],
        ],
    ]);

    $speaker = Speaker::query()->findOrFail($speaker->id);

    expect($speaker->bio)->toBe('<p>Pionera de la <strong>computación</strong>.</p>')
        ->and($speaker->tagline)->toBe('Escribió el primer programa')
        ->and($speaker->company)->toBe('Analytical Engines')
        ->and($speaker->job_title)->toBe('Matemática')
        ->and($speaker->country)->toBe('GB')
        ->and($speaker->social_links)->toHaveCount(3)
        // `jsonb` no conserva el orden de las claves.
        ->and($speaker->social_links[1])->toEqual(['network' => 'x', 'url' => 'https://twitter.com/ada']);
});

it('CA-021-2: the biography is saved sanitized, on create and on update', function (): void {
    [$event, $admin] = speakerScenario();
    $dirty = '<p onclick="alert(1)">Hola</p><script>alert(2)</script><a href="javascript:alert(3)">x</a><img src=x onerror=alert(4)>';

    $created = createSpeaker($admin, $event, ['bio' => $dirty]);
    $updated = app(UpdateSpeaker::class)->handle($admin, createSpeaker($admin, $event, ['first_name' => 'Otra']), ['bio' => $dirty]);

    foreach ([$created, $updated] as $speaker) {
        $bio = (string) Speaker::query()->findOrFail($speaker->id)->bio;

        expect($bio)->toContain('Hola')
            ->and($bio)->not->toContain('script', 'onclick', 'javascript', 'onerror', '<img');
    }
});

it('CA-021-2: an empty biography is stored as null', function (): void {
    [$event, $admin] = speakerScenario();

    expect(createSpeaker($admin, $event, ['bio' => '<p></p>'])->bio)->toBeNull();
});

it('CA-021-2: a repeated network, a non-https address or an address of another domain is rejected', function (array $links, string $key): void {
    [$event, $admin] = speakerScenario();

    expect(validationErrors(fn () => createSpeaker($admin, $event, ['social_links' => $links])))->toHaveKey($key)
        ->and(Speaker::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'repeated network' => [[['network' => 'x', 'url' => 'https://x.com/a'], ['network' => 'x', 'url' => 'https://twitter.com/b']], 'social_links'],
    'http' => [[['network' => 'linkedin', 'url' => 'http://linkedin.com/in/a']], 'social_links.0.url'],
    'javascript' => [[['network' => 'website', 'url' => 'javascript:alert(1)']], 'social_links.0.url'],
    'data' => [[['network' => 'website', 'url' => 'data:text/html,hola']], 'social_links.0.url'],
    'no scheme' => [[['network' => 'linkedin', 'url' => 'linkedin.com/in/a']], 'social_links.0.url'],
    'other domain' => [[['network' => 'linkedin', 'url' => 'https://evil.test/in/a']], 'social_links.0.url'],
    'look-alike domain' => [[['network' => 'linkedin', 'url' => 'https://notlinkedin.com/in/a']], 'social_links.0.url'],
    'domain as subdomain of attacker' => [[['network' => 'linkedin', 'url' => 'https://linkedin.com.evil.test/a']], 'social_links.0.url'],
    'credentials in url' => [[['network' => 'linkedin', 'url' => 'https://linkedin.com@evil.test/a']], 'social_links.0.url'],
    'network of another kind' => [[['network' => 'instagram', 'url' => 'https://facebook.com/a']], 'social_links.0.url'],
    'unknown network' => [[['network' => 'myspace', 'url' => 'https://myspace.com/a']], 'social_links.0.network'],
    'website localhost' => [[['network' => 'website', 'url' => 'https://localhost/a']], 'social_links.0.url'],
    'website ip' => [[['network' => 'website', 'url' => 'https://127.0.0.1/a']], 'social_links.0.url'],
    'website private name' => [[['network' => 'website', 'url' => 'https://intranet.local/a']], 'social_links.0.url'],
    'too long' => [[['network' => 'website', 'url' => 'https://example.com/'.str_repeat('a', 250)]], 'social_links.0.url'],
    'missing url' => [[['network' => 'x']], 'social_links.0.url'],
]);

it('CA-021-2: unknown keys inside a social link are dropped before saving', function (): void {
    [$event, $admin] = speakerScenario();

    $speaker = createSpeaker($admin, $event, ['social_links' => [['network' => 'x', 'url' => 'https://x.com/a', 'onclick' => 'x']]]);

    expect($speaker->social_links)->toEqual([['network' => 'x', 'url' => 'https://x.com/a']]);
});

it('CA-021-2: accepts the domain and subdomains of each network', function (string $network, string $url): void {
    [$event, $admin] = speakerScenario();

    $speaker = createSpeaker($admin, $event, ['social_links' => [['network' => $network, 'url' => $url]]]);

    expect($speaker->social_links)->toHaveCount(1);
})->with([
    ['linkedin', 'https://linkedin.com/in/ada'],
    ['linkedin', 'https://mx.linkedin.com/in/ada'],
    ['x', 'https://x.com/ada'],
    ['x', 'https://twitter.com/ada'],
    ['instagram', 'https://www.instagram.com/ada'],
    ['facebook', 'https://fb.com/ada'],
    ['facebook', 'https://www.facebook.com/ada'],
    ['youtube', 'https://youtu.be/abc'],
    ['youtube', 'https://www.youtube.com/@ada'],
    ['tiktok', 'https://www.tiktok.com/@ada'],
    ['website', 'https://ada.dev/about?x=1'],
]);

it('CA-021-2: limits the one-line description to 160 characters', function (): void {
    [$event, $admin] = speakerScenario();

    expect(validationErrors(fn () => createSpeaker($admin, $event, ['tagline' => str_repeat('a', 161)])))->toHaveKey('tagline')
        ->and(createSpeaker($admin, $event, ['tagline' => str_repeat('a', 160)])->tagline)->toHaveLength(160);
});

it('CA-021-3: stores a valid photo on the media disk and keeps only its path', function (): void {
    [$event, $admin] = speakerScenario();

    $speaker = createSpeaker($admin, $event, ['photo' => UploadedFile::fake()->image('retrato.png', 20, 20)]);

    expect($speaker->photo_path)->toStartWith("organizations/{$event->organization_id}/events/{$event->id}/media/photo/")
        ->and(Storage::disk(MediaDisk::name())->exists((string) $speaker->photo_path))->toBeTrue()
        ->and(mediaFiles())->toHaveCount(1);
});

it('CA-021-3: a photo of a type not allowed, fake by content or over 5 MB is rejected and nothing is stored', function (UploadedFile $file): void {
    [$event, $admin] = speakerScenario();

    expect(validationErrors(fn () => createSpeaker($admin, $event, ['photo' => $file])))->toHaveKey('photo')
        ->and(mediaFiles())->toBe([])
        ->and(Speaker::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'pdf' => fn () => UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%fake"),
    'text named png' => fn () => UploadedFile::fake()->createWithContent('a.png', 'esto no es una imagen'),
    'svg' => fn () => UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'php named jpg' => fn () => UploadedFile::fake()->createWithContent('a.jpg', '<?php echo 1;'),
    'over 5 MB' => fn () => UploadedFile::fake()->create('grande.png', 6000, 'image/png'),
]);

it('CA-021-3: a photo rejected when updating keeps the previous one and stores nothing', function (): void {
    [$event, $admin] = speakerScenario();
    $speaker = createSpeaker($admin, $event, ['photo' => UploadedFile::fake()->image('a.png')]);
    $previous = $speaker->photo_path;

    expect(validationErrors(fn () => app(UpdateSpeaker::class)->handle($admin, $speaker, ['photo' => UploadedFile::fake()->createWithContent('a.png', 'no')])))->toHaveKey('photo')
        ->and(Speaker::query()->findOrFail($speaker->id)->photo_path)->toBe($previous)
        ->and(mediaFiles())->toBe([$previous]);
});

it('CA-021-3: replacing the photo deletes the previous file and removing it deletes the file', function (): void {
    [$event, $admin] = speakerScenario();
    $speaker = createSpeaker($admin, $event, ['photo' => UploadedFile::fake()->image('a.png')]);
    $first = (string) $speaker->photo_path;

    $speaker = app(UpdateSpeaker::class)->handle($admin, $speaker, ['photo' => UploadedFile::fake()->image('b.jpg')]);
    $second = (string) $speaker->photo_path;

    expect($second)->not->toBe($first)
        ->and(mediaFiles())->toBe([$second]);

    $speaker = app(UpdateSpeaker::class)->handle($admin, $speaker, ['remove_photo' => true]);

    expect($speaker->photo_path)->toBeNull()
        ->and(mediaFiles())->toBe([]);
});

it('CA-021-3: when the database refuses the write, the stored photo is cleaned up', function (): void {
    [$event, $admin] = speakerScenario();
    // Se fuerza un fallo de la base DESPUÉS de guardar el archivo, con un disparador que rechaza el alta.
    DB::unprepared('CREATE FUNCTION speakers_fail() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION \'fallo simulado\'; END; $$ LANGUAGE plpgsql');
    DB::unprepared('CREATE TRIGGER speakers_fail_insert BEFORE INSERT ON speakers FOR EACH ROW EXECUTE FUNCTION speakers_fail()');

    expect(fn () => createSpeaker($admin, $event, ['photo' => UploadedFile::fake()->image('a.png')]))->toThrow(QueryException::class)
        ->and(mediaFiles())->toBe([]);
});

it('CA-021-1: links only categories of the speaker event, several at once, and replaces them on update', function (): void {
    [$event, $admin] = speakerScenario();
    $other = Event::factory()->create(['organization_id' => $event->organization_id]);
    $one = speakerCategory($event, 'Magistral');
    $two = speakerCategory($event, 'Panelista');
    $foreign = speakerCategory($other, 'De otro evento');

    expect(validationErrors(fn () => createSpeaker($admin, $event, ['category_ids' => [$foreign->id]])))->toHaveKey('category_ids.0')
        ->and(validationErrors(fn () => createSpeaker($admin, $event, ['category_ids' => ['no-es-uuid']])))->toHaveKey('category_ids.0');

    $speaker = createSpeaker($admin, $event, ['category_ids' => [$one->id, $two->id]]);

    expect($speaker->categories()->pluck('speaker_categories.id')->sort()->values()->all())->toBe(collect([$one->id, $two->id])->sort()->values()->all());

    app(UpdateSpeaker::class)->handle($admin, $speaker, ['category_ids' => [$two->id]]);

    expect($speaker->categories()->pluck('speaker_categories.id')->all())->toBe([$two->id]);

    // Quitar todas.
    app(UpdateSpeaker::class)->handle($admin, $speaker, ['category_ids' => []]);

    expect($speaker->categories()->count())->toBe(0);
});

it('CA-021-1: an update changes only the keys it receives and still requires first and last name', function (): void {
    [$event, $admin] = speakerScenario();
    $speaker = createSpeaker($admin, $event, ['company' => 'Analytical', 'email' => 'ada@example.com', 'bio' => '<p>Bio</p>']);

    $updated = app(UpdateSpeaker::class)->handle($admin, $speaker, ['job_title' => 'Matemática']);

    expect($updated->job_title)->toBe('Matemática')
        ->and($updated->company)->toBe('Analytical')
        ->and($updated->email)->toBe('ada@example.com')
        ->and($updated->bio)->toBe('<p>Bio</p>')
        ->and(validationErrors(fn () => app(UpdateSpeaker::class)->handle($admin, $speaker, ['last_name' => ''])))->toHaveKey('last_name')
        ->and(Speaker::query()->findOrFail($speaker->id)->last_name)->toBe('Lovelace');
});

it('CA-021-1: publishing and unpublishing is idempotent, and republishing clears the reversal mark', function (): void {
    [$event, $admin] = speakerScenario();
    $speaker = createSpeaker($admin, $event);
    $action = app(SetSpeakerPublication::class);

    $action->handle($admin, $speaker, false);
    $action->handle($admin, $speaker, false);

    expect(Speaker::query()->findOrFail($speaker->id)->is_published)->toBeFalse()
        ->and(Activity::query()->where('event', 'speaker_unpublished')->count())->toBe(1);

    DB::table('speakers')->where('id', $speaker->id)->update(['hidden_by_reversal_at' => now()]);
    $action->handle($admin, Speaker::query()->findOrFail($speaker->id), true);

    $fresh = Speaker::query()->findOrFail($speaker->id);

    expect($fresh->is_published)->toBeTrue()
        ->and($fresh->hidden_by_reversal_at)->toBeNull();
});

it('CA-021-1: editing is_published through UpdateSpeaker also clears the reversal mark', function (): void {
    [$event, $admin] = speakerScenario();
    $speaker = createSpeaker($admin, $event);
    DB::table('speakers')->where('id', $speaker->id)->update(['is_published' => false, 'hidden_by_reversal_at' => now()]);

    $fresh = app(UpdateSpeaker::class)->handle($admin, Speaker::query()->findOrFail($speaker->id), ['is_published' => true]);

    expect($fresh->is_published)->toBeTrue()->and($fresh->hidden_by_reversal_at)->toBeNull();
});

it('CA-021-16: deleting a speaker is logical, removes it from the lists and keeps the row and its photo', function (): void {
    [$event, $admin] = speakerScenario();
    $speaker = createSpeaker($admin, $event, ['photo' => UploadedFile::fake()->image('a.png')]);
    $keep = createSpeaker($admin, $event, ['first_name' => 'Otro']);

    app(DeleteSpeaker::class)->handle($admin, $speaker);

    expect(Speaker::query()->pluck('id')->all())->toBe([$keep->id])
        ->and(Speaker::query()->withTrashed()->count())->toBe(2)
        ->and(mediaFiles())->toHaveCount(1);
});

it('CA-021-15: org_admin edits and deletes; organizer edits but cannot delete; reviewer cannot do anything', function (): void {
    [$event, $admin] = speakerScenario();
    $organizer = Memberships::attach(User::factory()->create(), $event->organization, OrganizationRole::Organizer);
    $reviewer = Memberships::attach(User::factory()->create(), $event->organization, OrganizationRole::Reviewer);
    $speaker = createSpeaker($admin, $event);

    expect(fn () => createSpeaker($reviewer, $event, ['first_name' => 'X']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateSpeaker::class)->handle($reviewer, $speaker, ['company' => 'X']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SetSpeakerPublication::class)->handle($reviewer, $speaker, false))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteSpeaker::class)->handle($reviewer, $speaker))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteSpeaker::class)->handle($organizer, $speaker))->toThrow(AuthorizationException::class)
        ->and(createSpeaker($organizer, $event, ['first_name' => 'Org'])->first_name)->toBe('Org')
        ->and(app(UpdateSpeaker::class)->handle($organizer, $speaker, ['company' => 'Y'])->company)->toBe('Y');

    app(DeleteSpeaker::class)->handle($admin, $speaker);

    expect(Speaker::query()->count())->toBe(1);
});

it('CA-021-17: an admin of another organization cannot create, edit, publish or delete', function (): void {
    [$event, $admin] = speakerScenario();
    [, $outsider] = speakerScenario();
    $speaker = createSpeaker($admin, $event);
    $speaker = Speaker::query()->withoutGlobalScopes()->findOrFail($speaker->id);

    expect(fn () => createSpeaker($outsider, $event, ['first_name' => 'X']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateSpeaker::class)->handle($outsider, $speaker, ['company' => 'X']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SetSpeakerPublication::class)->handle($outsider, $speaker, false))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteSpeaker::class)->handle($outsider, $speaker))->toThrow(AuthorizationException::class)
        ->and(DB::table('speakers')->count())->toBe(1);
});

it('CA-021-15: audits create, update, publish and delete with identifiers and no personal data', function (): void {
    [$event, $admin] = speakerScenario();

    $speaker = createSpeaker($admin, $event, ['email' => 'ada@example.com', 'bio' => '<p>Secreto</p>']);
    app(UpdateSpeaker::class)->handle($admin, $speaker, ['company' => 'Analytical', 'bio' => '<p>Otro</p>']);
    app(SetSpeakerPublication::class)->handle($admin, $speaker, false);
    app(DeleteSpeaker::class)->handle($admin, $speaker);

    $entries = Activity::query()->where('event', 'like', 'speaker_%')->orderBy('id')->get();

    expect($entries->pluck('event')->all())->toBe(['speaker_created', 'speaker_updated', 'speaker_unpublished', 'speaker_deleted'])
        ->and($entries->every(fn (Activity $entry): bool => $entry->causer_id === $admin->id))->toBeTrue()
        ->and($entries[1]->properties->toArray())->toMatchArray(['speaker_id' => $speaker->id, 'changed' => ['company', 'bio']]);

    $dump = $entries->toJson();

    expect($dump)->not->toContain('Ada', 'Lovelace', 'ada@example.com', 'Secreto', 'Analytical');
});
