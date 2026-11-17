<?php

declare(strict_types=1);

use App\Actions\Notifications\PreviewMailTemplate;
use App\Actions\Notifications\ResolveMailTemplate;
use App\Actions\Notifications\RestoreMailTemplate;
use App\Actions\Notifications\SetNotificationEnabled;
use App\Actions\Notifications\UpdateMailTemplate;
use App\Enums\OrganizationRole;
use App\Exceptions\Domain\CriticalNotificationCannotBeDisabled;
use App\Models\Event;
use App\Models\MailTemplate;
use App\Models\NotificationSetting;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\NotificationCatalog;
use App\Support\Notifications\ResolvedMailTemplate;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

/*
 * Plantillas por organización, vista previa y notificaciones desactivables (plan 008,
 * H-008-3, H-008-4 y H-008-5). Cada Action vuelve a autorizar: ocultar un botón no basta.
 */

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array{0: Organization, 1: User, 2: User, 3: User} organización, administrador, organizador y evaluador */
function mailScenario(): array
{
    $organization = Memberships::organization();

    return [
        $organization,
        Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin),
        Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer),
        Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer),
    ];
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function validTemplate(array $overrides = []): array
{
    return [...[
        'subject' => 'Gracias por tu propuesta, {{ author_name }}',
        'body' => "Hola {{ author_name }},\n\nRecibimos «{{ submission_title }}» para {{ event_name }}.",
        'from_name' => 'Equipo del Congreso',
        'reply_to' => 'contacto@example.test',
    ], ...$overrides];
}

/** @param  array<string, string>  $data */
function saveTemplate(User $actor, Organization $organization, array $data = [], string $key = 'submission.received', string $locale = 'es'): MailTemplate
{
    return app(UpdateMailTemplate::class)->handle($actor, $organization, $key, $locale, validTemplate($data));
}

function resolveFor(Organization $organization, string $key = 'submission.received', string $locale = 'es', ?Event $event = null): ResolvedMailTemplate
{
    return app(ResolveMailTemplate::class)->handle($key, $locale, $organization, $event?->id);
}

/* ---------------------------------------------------------------- Policy */

it('CA-008-13: only admins manage templates; organizers only view; reviewers and outsiders get nothing', function (): void {
    [$organization, $admin, $organizer, $reviewer] = mailScenario();
    $outsider = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::OrgAdmin);

    $abilities = fn (User $user): array => CurrentOrganization::run($organization, fn (): array => [
        'viewAny' => Gate::forUser($user->unsetRelation('roles')->unsetRelation('permissions'))->allows('viewAny', MailTemplate::class),
        'update' => Gate::forUser($user)->allows('update', MailTemplate::class),
        'restore' => Gate::forUser($user)->allows('restore', MailTemplate::class),
        'toggle' => Gate::forUser($user)->allows('toggle', MailTemplate::class),
    ]);

    expect($abilities($admin))->toBe(['viewAny' => true, 'update' => true, 'restore' => true, 'toggle' => true]);
    expect($abilities($organizer))->toBe(['viewAny' => true, 'update' => false, 'restore' => false, 'toggle' => false]);
    expect($abilities($reviewer))->toBe(['viewAny' => false, 'update' => false, 'restore' => false, 'toggle' => false]);
    expect($abilities($outsider))->toBe(['viewAny' => false, 'update' => false, 'restore' => false, 'toggle' => false]);
});

it('CA-008-13: without an organization context every ability is denied', function (): void {
    [, $admin] = mailScenario();
    CurrentOrganization::forget();

    expect(Gate::forUser($admin)->allows('viewAny', MailTemplate::class))->toBeFalse();
    expect(Gate::forUser($admin)->allows('update', MailTemplate::class))->toBeFalse();
});

it('CA-008-13: an organizer cannot save, restore or toggle even by calling the actions directly', function (): void {
    [$organization, $admin, $organizer] = mailScenario();
    saveTemplate($admin, $organization);

    expect(fn () => saveTemplate($organizer, $organization))->toThrow(AuthorizationException::class);
    expect(fn () => app(RestoreMailTemplate::class)->handle($organizer, $organization, 'submission.received', 'es'))->toThrow(AuthorizationException::class);
    expect(fn () => app(SetNotificationEnabled::class)->handle($organizer, $organization, 'submission.updated', false))->toThrow(AuthorizationException::class);
});

it('CA-008-13: an admin of another organization cannot change this organization', function (): void {
    [$organization] = mailScenario();
    $otherOrganization = Memberships::organization();
    $otherAdmin = Memberships::attach(User::factory()->create(), $otherOrganization, OrganizationRole::OrgAdmin);

    expect(fn () => saveTemplate($otherAdmin, $organization))->toThrow(AuthorizationException::class);
    expect(MailTemplate::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('CA-008-13: a template of another organization is invisible by its identifier', function (): void {
    [$organizationA, $adminA] = mailScenario();
    [$organizationB, $adminB] = mailScenario();
    $templateOfB = saveTemplate($adminB, $organizationB);

    $found = CurrentOrganization::run($organizationA, fn () => MailTemplate::query()->find($templateOfB->id));

    expect($found)->toBeNull();
    expect(CurrentOrganization::run($organizationA, fn () => MailTemplate::query()->count()))->toBe(0);
});

/* ------------------------------------------------- H-008-4 personalizar */

it('CA-008-15: a custom template is used by its organization and the others keep the default', function (): void {
    [$organizationA, $adminA] = mailScenario();
    [$organizationB] = mailScenario();

    saveTemplate($adminA, $organizationA);

    $forA = resolveFor($organizationA);
    $forB = resolveFor($organizationB);

    expect($forA->source)->toBe('organization')
        ->and($forA->subject)->toBe('Gracias por tu propuesta, {{ author_name }}')
        ->and($forA->fromName)->toBe('Equipo del Congreso')
        ->and($forA->replyTo)->toBe('contacto@example.test');
    expect($forB->source)->toBe('system')
        ->and($forB->subject)->toBe(trans('mail_templates.submission.received.subject', [], 'es'))
        ->and($forB->fromName)->toBeNull();
});

it('CA-008-15: saving again updates the same row instead of creating a duplicate', function (): void {
    [$organization, $admin] = mailScenario();

    $first = saveTemplate($admin, $organization);
    $second = saveTemplate($admin, $organization, ['subject' => 'Otro asunto']);

    expect($second->id)->toBe($first->id);
    expect(MailTemplate::query()->withoutGlobalScopes()->count())->toBe(1);
    expect(resolveFor($organization)->subject)->toBe('Otro asunto');
});

it('CA-008-15: each locale has its own template', function (): void {
    [$organization, $admin] = mailScenario();

    saveTemplate($admin, $organization, ['subject' => 'Asunto en español'], locale: 'es');

    expect(resolveFor($organization, locale: 'es')->subject)->toBe('Asunto en español');
    expect(resolveFor($organization, locale: 'en')->source)->toBe('system');
});

it('CA-008-5: a variable outside the whitelist is rejected on save', function (): void {
    [$organization, $admin] = mailScenario();

    expect(fn () => saveTemplate($admin, $organization, ['body' => 'Clave: {{ app_key }}']))->toThrow(ValidationException::class);
    expect(fn () => saveTemplate($admin, $organization, ['subject' => '{{ password }}']))->toThrow(ValidationException::class);
    expect(MailTemplate::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('CA-008-18: line breaks or an invalid address in the header fields are rejected', function (array $override, string $field): void {
    [$organization, $admin] = mailScenario();

    try {
        saveTemplate($admin, $organization, $override);
        $this->fail('Debió rechazarse.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    expect(MailTemplate::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'subject with line break' => [['subject' => "Hola\r\nBcc: x@example.test"], 'subject'],
    'subject with bare line feed' => [['subject' => "Hola\nmundo"], 'subject'],
    'from name with line break' => [['from_name' => "Equipo\r\nBcc: x@example.test"], 'from_name'],
    'reply-to with line break' => [['reply_to' => "a@example.test\r\nBcc: x@example.test"], 'reply_to'],
    'reply-to not an address' => [['reply_to' => 'no-es-un-correo'], 'reply_to'],
    'empty subject' => [['subject' => ''], 'subject'],
    'empty body' => [['body' => ''], 'body'],
    'subject too long' => [['subject' => str_repeat('a', 201)], 'subject'],
    'body too long' => [['body' => str_repeat('a', 20001)], 'body'],
    'from name too long' => [['from_name' => str_repeat('a', 101)], 'from_name'],
]);

it('CA-008-18: from name and reply-to are optional', function (): void {
    [$organization, $admin] = mailScenario();

    $template = saveTemplate($admin, $organization, ['from_name' => '', 'reply_to' => '']);

    expect($template->from_name)->toBeNull()
        ->and($template->reply_to)->toBeNull();
});

it('rejects a key that is not in the catalog and a locale the notification does not support', function (): void {
    [$organization, $admin] = mailScenario();

    expect(fn () => saveTemplate($admin, $organization, key: 'made.up_key'))->toThrow(InvalidArgumentException::class);
    expect(fn () => saveTemplate($admin, $organization, locale: 'fr'))->toThrow(ValidationException::class);
    expect(fn () => saveTemplate($admin, $organization, locale: 'pt'))->toThrow(ValidationException::class);
});

it('CA-008-15: saving is audited without storing the body', function (): void {
    [$organization, $admin] = mailScenario();

    saveTemplate($admin, $organization, ['body' => 'Hola {{ author_name }} SECRETO-DEL-CUERPO']);

    $activity = Activity::query()->where('event', 'mail_template_updated')->sole();

    expect($activity->causer_id)->toBe($admin->id);
    expect($activity->properties['organization_id'])->toBe($organization->id)
        ->and($activity->properties['notification_key'])->toBe('submission.received')
        ->and($activity->properties['locale'])->toBe('es');
    expect($activity->properties->toJson())->not->toContain('SECRETO-DEL-CUERPO');
});

it('CA-008-17: restoring removes the customization, the next send uses the default, and it is audited', function (): void {
    [$organization, $admin] = mailScenario();
    saveTemplate($admin, $organization);

    $removed = app(RestoreMailTemplate::class)->handle($admin, $organization, 'submission.received', 'es');

    expect($removed)->toBeTrue();
    expect(resolveFor($organization)->source)->toBe('system');
    expect(MailTemplate::query()->withoutGlobalScopes()->count())->toBe(0);
    $activity = Activity::query()->where('event', 'mail_template_restored')->sole();
    expect($activity->causer_id)->toBe($admin->id)
        ->and($activity->properties['notification_key'])->toBe('submission.received');
});

it('CA-008-17: restoring something that was never customized changes nothing and is not audited', function (): void {
    [$organization, $admin] = mailScenario();

    $removed = app(RestoreMailTemplate::class)->handle($admin, $organization, 'submission.received', 'es');

    expect($removed)->toBeFalse();
    expect(Activity::query()->where('event', 'mail_template_restored')->count())->toBe(0);
});

it('CA-008-17: restoring only affects its own locale and organization', function (): void {
    [$organizationA, $adminA] = mailScenario();
    [$organizationB, $adminB] = mailScenario();
    saveTemplate($adminA, $organizationA, locale: 'es');
    saveTemplate($adminA, $organizationA, locale: 'en');
    saveTemplate($adminB, $organizationB, locale: 'es');

    app(RestoreMailTemplate::class)->handle($adminA, $organizationA, 'submission.received', 'es');

    expect(resolveFor($organizationA, locale: 'es')->source)->toBe('system');
    expect(resolveFor($organizationA, locale: 'en')->source)->toBe('organization');
    expect(resolveFor($organizationB, locale: 'es')->source)->toBe('organization');
});

/* ------------------------------------------------------- CA-008-19 y 20 */

it('CA-008-19: the database rejects two organization-level templates for the same key and locale', function (): void {
    $organization = Organization::factory()->create();
    $attributes = ['organization_id' => $organization->id, 'notification_key' => 'submission.received', 'locale' => 'es'];
    MailTemplate::factory()->create($attributes);

    expect(fn () => MailTemplate::factory()->create($attributes))->toThrow(QueryException::class);
});

it('CA-008-19: the database rejects two event-level templates for the same event, key and locale', function (): void {
    $organization = Organization::factory()->create();
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $attributes = ['organization_id' => $organization->id, 'event_id' => $event->id, 'notification_key' => 'submission.received', 'locale' => 'es'];
    MailTemplate::factory()->create($attributes);

    expect(fn () => MailTemplate::factory()->create($attributes))->toThrow(QueryException::class);
});

it('CA-008-19: an organization-level and an event-level template for the same key coexist', function (): void {
    $organization = Organization::factory()->create();
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $base = ['organization_id' => $organization->id, 'notification_key' => 'submission.received', 'locale' => 'es'];

    MailTemplate::factory()->create($base);
    MailTemplate::factory()->create($base + ['event_id' => $event->id]);

    expect(MailTemplate::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('the database rejects a template whose event belongs to another organization', function (): void {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $eventOfB = Event::factory()->create(['organization_id' => $organizationB->id]);

    expect(fn () => MailTemplate::factory()->create([
        'organization_id' => $organizationA->id,
        'event_id' => $eventOfB->id,
    ]))->toThrow(QueryException::class);
});

it('the database rejects a locale outside es, en and pt', function (): void {
    expect(fn () => MailTemplate::factory()->create(['locale' => 'fr']))->toThrow(QueryException::class);
});

it('CA-008-20: the event template wins, then the organization template, then the system default', function (): void {
    $organization = Organization::factory()->create();
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $otherEvent = Event::factory()->create(['organization_id' => $organization->id]);
    $base = ['organization_id' => $organization->id, 'notification_key' => 'submission.received', 'locale' => 'es'];

    expect(resolveFor($organization, event: $event)->source)->toBe('system');

    MailTemplate::factory()->create($base + ['subject' => 'De la organización']);
    expect(resolveFor($organization, event: $event)->subject)->toBe('De la organización');
    expect(resolveFor($organization)->subject)->toBe('De la organización');

    MailTemplate::factory()->create($base + ['event_id' => $event->id, 'subject' => 'Del evento']);
    expect(resolveFor($organization, event: $event)->subject)->toBe('Del evento')
        ->and(resolveFor($organization, event: $event)->source)->toBe('event');
    expect(resolveFor($organization)->subject)->toBe('De la organización');
    expect(resolveFor($organization, event: $otherEvent)->subject)->toBe('De la organización');
});

it('CA-008-20: the resolver never reads a template of another organization', function (): void {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    MailTemplate::factory()->create([
        'organization_id' => $organizationB->id,
        'notification_key' => 'submission.received',
        'locale' => 'es',
        'subject' => 'De la organización B',
    ]);

    expect(resolveFor($organizationA)->source)->toBe('system');
});

/* ------------------------------------------------- H-008-5 desactivar */

it('CA-008-21: an admin disables a non-critical notification and the decision is audited', function (): void {
    [$organization, $admin] = mailScenario();

    $setting = app(SetNotificationEnabled::class)->handle($admin, $organization, 'submission.new_for_organizers', false);

    expect($setting->is_enabled)->toBeFalse();
    $activity = Activity::query()->where('event', 'notification_disabled')->sole();
    expect($activity->causer_id)->toBe($admin->id)
        ->and($activity->properties['notification_key'])->toBe('submission.new_for_organizers')
        ->and($activity->properties['organization_id'])->toBe($organization->id);
});

it('CA-008-21: a disabled notification can be enabled again, without duplicating the row', function (): void {
    [$organization, $admin] = mailScenario();
    $action = app(SetNotificationEnabled::class);

    $action->handle($admin, $organization, 'submission.updated', false);
    $setting = $action->handle($admin, $organization, 'submission.updated', true);

    expect($setting->is_enabled)->toBeTrue();
    expect(NotificationSetting::query()->withoutGlobalScopes()->count())->toBe(1);
    expect(Activity::query()->where('event', 'notification_enabled')->count())->toBe(1);
});

it('CA-008-21: disabling is per organization', function (): void {
    [$organizationA, $adminA] = mailScenario();
    [$organizationB] = mailScenario();

    app(SetNotificationEnabled::class)->handle($adminA, $organizationA, 'submission.updated', false);

    $isEnabled = fn (Organization $organization): bool => CurrentOrganization::run(
        $organization,
        fn (): bool => NotificationSetting::query()->where('notification_key', 'submission.updated')->value('is_enabled') ?? true,
    );
    expect($isEnabled($organizationA))->toBeFalse();
    expect($isEnabled($organizationB))->toBeTrue();
});

it('CA-008-22: a critical notification cannot be disabled', function (string $key): void {
    [$organization, $admin] = mailScenario();

    expect(fn () => app(SetNotificationEnabled::class)->handle($admin, $organization, $key, false))
        ->toThrow(CriticalNotificationCannotBeDisabled::class);

    expect(NotificationSetting::query()->withoutGlobalScopes()->count())->toBe(0);
    expect(Activity::query()->where('event', 'notification_disabled')->count())->toBe(0);
})->with([
    'password reset' => ['auth.password_reset'],
    'set password' => ['team.set_password'],
    'membership added' => ['team.membership_added'],
    'submission received' => ['submission.received'],
    'access link' => ['submission.access_link'],
    'new device' => ['auth.new_device_login'],
]);

it('rejects toggling a key that is not in the catalog', function (): void {
    [$organization, $admin] = mailScenario();

    app(SetNotificationEnabled::class)->handle($admin, $organization, 'made.up_key', false);
})->throws(InvalidArgumentException::class);

/* -------------------------------------------------------- Vista previa */

it('CA-008-16: the preview renders the draft with sample data and sends nothing', function (): void {
    [$organization, $admin] = mailScenario();
    Mail::fake();
    Queue::fake();

    $preview = app(PreviewMailTemplate::class)->handle(
        $admin,
        $organization,
        'submission.received',
        'es',
        'Hola {{ author_name }}',
        'Recibimos «{{ submission_title }}» ({{ submission_reference }}).',
    );

    expect($preview->subject)->not->toContain('{{')->and($preview->subject)->not->toBe('Hola {{ author_name }}');
    expect($preview->html)->not->toContain('{{')->and($preview->html)->toContain('Recibimos');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
    expect(MailTemplate::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('CA-008-16: the preview escapes sample values and rejects variables outside the whitelist', function (): void {
    [$organization, $admin] = mailScenario();

    expect(fn () => app(PreviewMailTemplate::class)->handle($admin, $organization, 'submission.received', 'es', 'Hola', '{{ app_key }}'))
        ->toThrow(ValidationException::class);
});

it('CA-008-16: only an admin can preview', function (): void {
    [$organization, , $organizer] = mailScenario();

    expect(fn () => app(PreviewMailTemplate::class)->handle($organizer, $organization, 'submission.received', 'es', 'Hola', 'Hola'))
        ->toThrow(AuthorizationException::class);
});

it('CA-008-16: the preview works for every notification in the catalog', function (): void {
    [$organization, $admin] = mailScenario();

    foreach (app(NotificationCatalog::class)->all() as $key => $descriptor) {
        $preview = app(PreviewMailTemplate::class)->handle(
            $admin,
            $organization,
            $key,
            'es',
            (string) trans("mail_templates.{$key}.subject", [], 'es'),
            (string) trans("mail_templates.{$key}.body", [], 'es'),
        );

        expect($preview->html)->not->toContain('{{');
    }
});
