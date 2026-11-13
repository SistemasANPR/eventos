<?php

declare(strict_types=1);

use App\Actions\Notifications\SetNotificationEnabled;
use App\Actions\Notifications\UpdateMailTemplate;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Mail\EditMailTemplate;
use App\Filament\Pages\Mail\MailCatalog;
use App\Models\MailTemplate;
use App\Models\NotificationSetting;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\NotificationCatalog;
use App\Support\Notifications\NotificationOverview;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\Memberships;

/*
 * Pantallas del panel para los correos (plan 008, H-008-3, H-008-4 y H-008-5). Son delgadas:
 * las reglas viven en las Actions; aquí se comprueba lo que se ve, lo que se oculta y que
 * ocultar no sustituye a autorizar.
 */

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

function mailPanelSession(Organization $organization, User $user): void
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
}

/** @return array{0: Organization, 1: User, 2: User, 3: User} organización, administrador, organizador y evaluador */
function panelMailScenario(): array
{
    $organization = Memberships::organization();

    return [
        $organization,
        Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin),
        Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer),
        Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer),
    ];
}

/* ------------------------------------------------------------ Catálogo */

it('CA-008-12: an admin sees every catalog notification with its name and whether it is critical', function (): void {
    [$organization, $admin] = panelMailScenario();
    mailPanelSession($organization, $admin);

    $component = Livewire::test(MailCatalog::class)->assertSuccessful();

    foreach (app(NotificationCatalog::class)->all() as $descriptor) {
        $component->assertSee($descriptor->name());
    }

    $component->assertSee(__('mail_admin.catalog.critical_yes'))->assertSee(__('mail_admin.catalog.critical_no'));
});

it('CA-008-12: the status shows default, customized or disabled', function (): void {
    [$organization, $admin] = panelMailScenario();
    app(UpdateMailTemplate::class)->handle($admin, $organization, 'submission.received', 'es', [
        'subject' => 'Gracias', 'body' => 'Hola {{ author_name }}', 'from_name' => '', 'reply_to' => '',
    ]);
    app(SetNotificationEnabled::class)->handle($admin, $organization, 'submission.updated', false);
    mailPanelSession($organization, $admin);

    Livewire::test(MailCatalog::class)
        ->assertSee(__('mail_admin.status.default'))
        ->assertSee(__('mail_admin.status.customized'))
        ->assertSee(__('mail_admin.status.disabled'));
});

it('CA-008-12: the overview marks a critical notification as never disabled', function (): void {
    [$organization, $admin] = panelMailScenario();
    NotificationSetting::factory()->create([
        'organization_id' => $organization->id,
        'notification_key' => 'submission.received',
        'is_enabled' => false,
    ]);

    $overview = app(NotificationOverview::class)->forOrganization($organization);

    expect($overview['submission.received']['enabled'])->toBeTrue()
        ->and($overview['submission.received']['status'])->toBe('default');
});

it('CA-008-12: an organizer with view permission sees the catalog but gets no way to change it', function (): void {
    [$organization, , $organizer] = panelMailScenario();
    mailPanelSession($organization, $organizer);

    Livewire::test(MailCatalog::class)
        ->assertSuccessful()
        ->assertTableActionHidden('disable', 'submission.updated')
        ->assertTableActionHidden('enable', 'submission.updated')
        ->assertTableActionHidden('restore', 'submission.updated');
});

it('CA-008-13: a member without view permission gets 403 on the catalog and on a template', function (): void {
    [$organization, , , $reviewer] = panelMailScenario();
    mailPanelSession($organization, $reviewer);

    Livewire::test(MailCatalog::class)->assertForbidden();
    Livewire::test(EditMailTemplate::class, ['key' => 'submission.received'])->assertForbidden();
});

it('CA-008-13: an admin of another organization cannot open this organization\'s screens', function (): void {
    [$organization] = panelMailScenario();
    $outsider = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::OrgAdmin);
    mailPanelSession($organization, $outsider);

    Livewire::test(MailCatalog::class)->assertForbidden();
});

it('CA-008-13: an unknown notification key answers 404', function (): void {
    [$organization, $admin] = panelMailScenario();
    mailPanelSession($organization, $admin);

    Livewire::test(EditMailTemplate::class, ['key' => 'made.up_key'])->assertNotFound();
});

it('CA-008-13: the screen never shows a template customized by another organization', function (): void {
    [$organizationA, $adminA] = panelMailScenario();
    [$organizationB, $adminB] = panelMailScenario();
    app(UpdateMailTemplate::class)->handle($adminB, $organizationB, 'submission.received', 'es', [
        'subject' => 'ASUNTO-SECRETO-DE-B', 'body' => 'Cuerpo {{ author_name }}', 'from_name' => '', 'reply_to' => '',
    ]);
    mailPanelSession($organizationA, $adminA);

    Livewire::test(EditMailTemplate::class, ['key' => 'submission.received'])
        ->assertSet('source', 'system')
        ->assertDontSee('ASUNTO-SECRETO-DE-B');
    Livewire::test(MailCatalog::class)->assertDontSee(__('mail_admin.status.customized'));
});

/* ---------------------------------------------- Desactivar (H-008-5) */

it('CA-008-21: an admin disables and enables a non-critical notification from the catalog', function (): void {
    [$organization, $admin] = panelMailScenario();
    mailPanelSession($organization, $admin);
    $component = Livewire::test(MailCatalog::class);

    $component->callTableAction('disable', 'submission.updated')->assertHasNoTableActionErrors();
    expect(NotificationSetting::query()->where('notification_key', 'submission.updated')->value('is_enabled'))->toBeFalse();
    $component->assertTableActionVisible('enable', 'submission.updated')->assertTableActionHidden('disable', 'submission.updated');

    $component->callTableAction('enable', 'submission.updated');
    expect(NotificationSetting::query()->where('notification_key', 'submission.updated')->value('is_enabled'))->toBeTrue();
});

it('CA-008-22: a critical notification offers neither disable nor enable', function (string $key): void {
    [$organization, $admin] = panelMailScenario();
    mailPanelSession($organization, $admin);

    Livewire::test(MailCatalog::class)
        ->assertTableActionHidden('disable', $key)
        ->assertTableActionHidden('enable', $key);
})->with(['auth.password_reset', 'team.set_password', 'team.membership_added', 'submission.received', 'submission.access_link']);

it('CA-008-22: calling the disable action on a critical notification changes nothing', function (): void {
    [$organization, $admin] = panelMailScenario();
    mailPanelSession($organization, $admin);

    try {
        Livewire::test(MailCatalog::class)->callTableAction('disable', 'submission.received');
    } catch (Throwable) {
        // La acción está oculta: Filament puede rechazarla antes de ejecutarla.
    }

    expect(NotificationSetting::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('CA-008-17: an admin restores a customized notification from the catalog', function (): void {
    [$organization, $admin] = panelMailScenario();
    foreach (['es', 'en'] as $locale) {
        app(UpdateMailTemplate::class)->handle($admin, $organization, 'submission.received', $locale, [
            'subject' => 'Asunto '.$locale, 'body' => 'Hola {{ author_name }}', 'from_name' => '', 'reply_to' => '',
        ]);
    }
    mailPanelSession($organization, $admin);

    Livewire::test(MailCatalog::class)
        ->assertTableActionVisible('restore', 'submission.received')
        ->callTableAction('restore', 'submission.received');

    expect(MailTemplate::query()->count())->toBe(0);
});

/* ------------------------------------------------------------- Edición */

it('H-008-4: opening a template that was never customized shows the system default', function (): void {
    [$organization, $admin] = panelMailScenario();
    mailPanelSession($organization, $admin);
    app()->setLocale('es');

    Livewire::test(EditMailTemplate::class, ['key' => 'submission.received'])
        ->assertSuccessful()
        ->assertSet('source', 'system')
        ->assertSet('data.locale', 'es')
        ->assertSet('data.subject', trans('mail_templates.submission.received.subject', [], 'es'));
});

it('CA-008-15: an admin saves a template and it becomes the organization\'s own', function (): void {
    [$organization, $admin] = panelMailScenario();
    mailPanelSession($organization, $admin);

    Livewire::test(EditMailTemplate::class, ['key' => 'submission.received'])
        ->fillForm([
            'locale' => 'es',
            'subject' => 'Gracias, {{ author_name }}',
            'body' => 'Recibimos «{{ submission_title }}».',
            'from_name' => 'Congreso',
            'reply_to' => 'contacto@example.test',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('source', 'organization');

    $template = MailTemplate::query()->sole();
    expect($template->subject)->toBe('Gracias, {{ author_name }}')
        ->and($template->from_name)->toBe('Congreso')
        ->and($template->reply_to)->toBe('contacto@example.test');
});

it('CA-008-5: a variable outside the whitelist shows an error on the field and saves nothing', function (): void {
    [$organization, $admin] = panelMailScenario();
    mailPanelSession($organization, $admin);

    Livewire::test(EditMailTemplate::class, ['key' => 'submission.received'])
        ->fillForm(['locale' => 'es', 'subject' => 'Hola', 'body' => 'Clave: {{ app_key }}'])
        ->call('save')
        ->assertHasFormErrors(['body']);

    expect(MailTemplate::query()->count())->toBe(0);
});

it('CA-008-18: a subject with a line break shows an error on the field', function (): void {
    [$organization, $admin] = panelMailScenario();
    mailPanelSession($organization, $admin);

    Livewire::test(EditMailTemplate::class, ['key' => 'submission.received'])
        ->fillForm(['locale' => 'es', 'subject' => "Hola\r\nBcc: x@example.test", 'body' => 'Hola {{ author_name }}'])
        ->call('save')
        ->assertHasFormErrors(['subject']);

    expect(MailTemplate::query()->count())->toBe(0);
});

it('CA-008-18: an invalid reply-to address shows an error on the field', function (): void {
    [$organization, $admin] = panelMailScenario();
    mailPanelSession($organization, $admin);

    Livewire::test(EditMailTemplate::class, ['key' => 'submission.received'])
        ->fillForm(['locale' => 'es', 'subject' => 'Hola', 'body' => 'Hola {{ author_name }}', 'reply_to' => 'no-es-correo'])
        ->call('save')
        ->assertHasFormErrors(['reply_to']);
});

it('H-008-4: switching language loads that language\'s template', function (): void {
    [$organization, $admin] = panelMailScenario();
    app(UpdateMailTemplate::class)->handle($admin, $organization, 'submission.received', 'en', [
        'subject' => 'English subject', 'body' => 'Hello {{ author_name }}', 'from_name' => '', 'reply_to' => '',
    ]);
    mailPanelSession($organization, $admin);

    Livewire::test(EditMailTemplate::class, ['key' => 'submission.received'])
        ->set('data.locale', 'en')
        ->assertSet('data.subject', 'English subject')
        ->assertSet('source', 'organization')
        ->set('data.locale', 'es')
        ->assertSet('source', 'system');
});

it('CA-008-16: the preview shows the draft with sample data and sends nothing', function (): void {
    [$organization, $admin] = panelMailScenario();
    mailPanelSession($organization, $admin);
    Mail::fake();
    Queue::fake();

    $component = Livewire::test(EditMailTemplate::class, ['key' => 'submission.received'])
        ->fillForm(['locale' => 'es', 'subject' => 'Hola {{ author_name }}', 'body' => 'Recibimos «{{ submission_title }}».'])
        ->call('preview');

    expect($component->get('preview.subject'))->toContain('Ana Pérez')->and($component->get('preview.subject'))->not->toContain('{{');
    expect($component->get('preview.html'))->toContain('Recibimos')->and($component->get('preview.html'))->not->toContain('{{');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
    expect(MailTemplate::query()->count())->toBe(0);
});

it('CA-008-16: the preview refuses a draft with a variable outside the whitelist', function (): void {
    [$organization, $admin] = panelMailScenario();
    mailPanelSession($organization, $admin);

    Livewire::test(EditMailTemplate::class, ['key' => 'submission.received'])
        ->fillForm(['locale' => 'es', 'subject' => 'Hola', 'body' => '{{ app_key }}'])
        ->call('preview')
        ->assertSet('preview', null);
});

it('CA-008-17: restoring from the edit screen goes back to the system default', function (): void {
    [$organization, $admin] = panelMailScenario();
    app(UpdateMailTemplate::class)->handle($admin, $organization, 'submission.received', 'es', [
        'subject' => 'Mi asunto', 'body' => 'Hola {{ author_name }}', 'from_name' => '', 'reply_to' => '',
    ]);
    mailPanelSession($organization, $admin);

    Livewire::test(EditMailTemplate::class, ['key' => 'submission.received'])
        ->assertSet('source', 'organization')
        ->call('restore')
        ->assertSet('source', 'system')
        ->assertSet('data.subject', trans('mail_templates.submission.received.subject', [], 'es'));

    expect(MailTemplate::query()->count())->toBe(0);
});

it('CA-008-13: an organizer can open a template to read it but cannot save, preview or restore', function (): void {
    [$organization, $admin, $organizer] = panelMailScenario();
    app(UpdateMailTemplate::class)->handle($admin, $organization, 'submission.received', 'es', [
        'subject' => 'Original', 'body' => 'Hola {{ author_name }}', 'from_name' => '', 'reply_to' => '',
    ]);
    mailPanelSession($organization, $organizer);

    $component = Livewire::test(EditMailTemplate::class, ['key' => 'submission.received'])
        ->assertSuccessful()
        ->assertSet('data.subject', 'Original');

    $component->call('save')->assertForbidden();
    Livewire::test(EditMailTemplate::class, ['key' => 'submission.received'])->call('preview')->assertForbidden();
    Livewire::test(EditMailTemplate::class, ['key' => 'submission.received'])->call('restore')->assertForbidden();

    expect(MailTemplate::query()->sole()->subject)->toBe('Original');
});
