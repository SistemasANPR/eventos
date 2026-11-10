<?php

declare(strict_types=1);

use App\Actions\Privacy\CreatePrivacyNoticeDraft;
use App\Actions\Privacy\PublishPrivacyNotice;
use App\Actions\Privacy\UpdatePrivacyNoticeDraft;
use App\Enums\OrganizationRole;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array{0: Organization, 1: Event, 2: User} organización con roles, evento (idioma por defecto es) y administrador */
function noticeScenario(): array
{
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $event = Event::factory()->create([
        'organization_id' => $organization->id,
        'locales' => ['es', 'en'],
        'default_locale' => 'es',
    ]);

    return [$organization, $event, $admin];
}

it('CA-003-11: creates version 1 as an editable draft', function (): void {
    [, $event, $admin] = noticeScenario();

    $notice = app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Aviso en español</p>']);

    expect($notice->version)->toBe(1)
        ->and($notice->published_at)->toBeNull()
        ->and($notice->published_by)->toBeNull()
        ->and($notice->event_id)->toBe($event->id)
        ->and($notice->getTranslation('body', 'es'))->toBe('<p>Aviso en español</p>');

    $edited = app(UpdatePrivacyNoticeDraft::class)->handle($admin, $notice, ['es' => '<p>Texto corregido</p>', 'en' => '<p>English</p>']);

    expect($edited->getTranslation('body', 'es'))->toBe('<p>Texto corregido</p>')
        ->and($edited->getTranslation('body', 'en'))->toBe('<p>English</p>')
        ->and($edited->version)->toBe(1);
});

it('CA-003-11: refuses a draft with no text at all', function (): void {
    [, $event, $admin] = noticeScenario();

    expect(fn () => app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '   ', 'en' => '']))
        ->toThrow(ValidationException::class);
});

it('CA-003-11: refuses languages outside es, en and pt', function (): void {
    [, $event, $admin] = noticeScenario();

    expect(fn () => app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['fr' => '<p>Bonjour</p>']))
        ->toThrow(ValidationException::class);
});

it('CA-003-12: refuses to publish a draft without text in the default language and names the field', function (): void {
    [, $event, $admin] = noticeScenario();
    $draft = app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['en' => '<p>Only English</p>']);

    try {
        app(PublishPrivacyNotice::class)->handle($admin, $draft);
        $this->fail('Se esperaba ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('body.es');
    }

    expect($draft->fresh()->published_at)->toBeNull();
});

it('CA-003-13: publishes a complete draft with date and author, and audits it', function (): void {
    [, $event, $admin] = noticeScenario();
    $draft = app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Aviso</p>']);

    $published = app(PublishPrivacyNotice::class)->handle($admin, $draft);

    expect($published->published_at)->not->toBeNull()
        ->and($published->published_by)->toBe($admin->id);

    $entry = Activity::query()->where('event', 'privacy_notice_published')->sole();
    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->subject_id)->toBe($published->id)
        ->and($entry->properties->all())->toMatchArray(['version' => 1]);
});

it('CA-003-14: a published version cannot be edited, republished or deleted', function (): void {
    [$organization, $event, $admin] = noticeScenario();
    $notice = app(PublishPrivacyNotice::class)->handle(
        $admin,
        app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Aviso</p>']),
    );

    expect(fn () => app(UpdatePrivacyNoticeDraft::class)->handle($admin, $notice, ['es' => '<p>Cambio</p>']))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(PublishPrivacyNotice::class)->handle($admin, $notice))
        ->toThrow(ValidationException::class)
        ->and(fn () => DB::transaction(fn () => $notice->delete()))
        ->toThrow(QueryException::class)
        ->and(Gate::forUser($admin)->allows('delete', $notice))->toBeFalse();

    expect(CurrentOrganization::run($organization, fn () => PrivacyNotice::query()->sole()->getTranslation('body', 'es')))->toBe('<p>Aviso</p>');
});

it('CA-003-14: a new version starts from the published one as the next draft and the previous stays current', function (): void {
    [$organization, $event, $admin] = noticeScenario();
    $first = app(PublishPrivacyNotice::class)->handle(
        $admin,
        app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Versión uno</p>']),
    );

    $second = app(CreatePrivacyNoticeDraft::class)->handle($admin, $event);

    expect($second->version)->toBe(2)
        ->and($second->published_at)->toBeNull()
        ->and($second->getTranslation('body', 'es'))->toBe('<p>Versión uno</p>');

    CurrentOrganization::run($organization, function () use ($event, $first): void {
        expect(PrivacyNotice::currentPublished($event)?->id)->toBe($first->id);
    });
});

it('CA-003-14: a second open draft is refused', function (): void {
    [, $event, $admin] = noticeScenario();
    app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Borrador</p>']);

    expect(fn () => app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Otro</p>']))
        ->toThrow(ValidationException::class);
});

it('CA-003-14: with no previous version a new draft needs its own text', function (): void {
    [, $event, $admin] = noticeScenario();

    expect(fn () => app(CreatePrivacyNoticeDraft::class)->handle($admin, $event))->toThrow(ValidationException::class);
});

it('CA-003-15: sanitizes the text before saving, on create and on update', function (): void {
    [, $event, $admin] = noticeScenario();
    $dirty = '<p onclick="x()">Hola</p><script>alert(1)</script><a href="javascript:alert(1)">enlace</a><img src=x onerror=alert(1)>';

    $draft = app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => $dirty]);
    $stored = (string) $draft->fresh()->getRawOriginal('body');

    expect($stored)->not->toContain('script', 'alert', 'onclick', 'onerror', 'javascript', '<img');
    expect($draft->getTranslation('body', 'es'))->toContain('Hola', 'enlace');

    $updated = app(UpdatePrivacyNoticeDraft::class)->handle($admin, $draft, ['es' => '<p>Ok</p><iframe src="https://evil.test"></iframe><style>p{}</style>']);

    expect((string) $updated->fresh()->getRawOriginal('body'))->not->toContain('iframe', 'style');
});

it('CA-003-18: an organizer without privacy_notices.manage cannot create, edit or publish', function (): void {
    [$organization, $event, $admin] = noticeScenario();
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    $draft = app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Aviso</p>']);

    expect(fn () => app(CreatePrivacyNoticeDraft::class)->handle($organizer, $event, ['es' => '<p>X</p>']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdatePrivacyNoticeDraft::class)->handle($organizer, $draft, ['es' => '<p>X</p>']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(PublishPrivacyNotice::class)->handle($organizer, $draft))
        ->toThrow(AuthorizationException::class);

    expect($draft->fresh()->published_at)->toBeNull();
});

it('CA-003-18: an admin of another organization cannot touch the notice', function (): void {
    [, $event, $admin] = noticeScenario();
    $draft = app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Aviso</p>']);
    $organizationB = Memberships::organization();
    $adminB = Memberships::attach(User::factory()->create(), $organizationB, OrganizationRole::OrgAdmin);

    expect(fn () => app(PublishPrivacyNotice::class)->handle($adminB, $draft))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(CreatePrivacyNoticeDraft::class)->handle($adminB, $event, ['es' => '<p>X</p>']))
        ->toThrow(AuthorizationException::class);

    expect($draft->fresh()->published_at)->toBeNull();
});

it('CA-003-18: a deactivated admin cannot publish', function (): void {
    [$organization, $event, $admin] = noticeScenario();
    $draft = app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Aviso</p>']);
    $admin->organizations()->updateExistingPivot($organization->id, ['status' => 'deactivated', 'deactivated_at' => now()]);

    expect(fn () => app(PublishPrivacyNotice::class)->handle($admin->fresh(), $draft))->toThrow(AuthorizationException::class);
});

it('CA-003-20: currentPublished returns the latest published version, not a newer draft, and nothing when none', function (): void {
    [$organization, $event, $admin] = noticeScenario();

    CurrentOrganization::run($organization, fn () => expect(PrivacyNotice::currentPublished($event))->toBeNull());

    $first = app(PublishPrivacyNotice::class)->handle($admin, app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Uno</p>']));
    $draft = app(CreatePrivacyNoticeDraft::class)->handle($admin, $event);

    CurrentOrganization::run($organization, fn () => expect(PrivacyNotice::currentPublished($event)?->id)->toBe($first->id));

    app(UpdatePrivacyNoticeDraft::class)->handle($admin, $draft, ['es' => '<p>Dos</p>']);
    $second = app(PublishPrivacyNotice::class)->handle($admin, $draft->fresh());

    CurrentOrganization::run($organization, fn () => expect(PrivacyNotice::currentPublished($event)?->id)->toBe($second->id));
});

it('CA-003-20: currentPublished never returns the notice of another event or organization', function (): void {
    [$organization, $event, $admin] = noticeScenario();
    $otherEvent = Event::factory()->create(['organization_id' => $organization->id]);
    app(PublishPrivacyNotice::class)->handle($admin, app(CreatePrivacyNoticeDraft::class)->handle($admin, $otherEvent, ['es' => '<p>Otro evento</p>']));

    CurrentOrganization::run($organization, fn () => expect(PrivacyNotice::currentPublished($event))->toBeNull());
});
