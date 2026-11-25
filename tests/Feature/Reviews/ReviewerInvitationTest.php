<?php

declare(strict_types=1);

use App\Actions\Notifications\RenderMailTemplate;
use App\Actions\Reviews\InviteReviewer;
use App\Enums\EventAssignmentRole;
use App\Enums\OrganizationRole;
use App\Enums\ReviewerInvitationOutcome;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Auth\ResetPassword;
use App\Jobs\SendTransactionalMail;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\User;
use App\Support\Notifications\NotificationCatalog;
use App\Support\Notifications\SampleMailData;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 011, F3b: invitar a un evaluador (H-011-8). Cada prueba usa un solo rol de quien invita por
 * organización: la caché de permisos de Spatie se comparte dentro del proceso. La 2FA obligatoria está
 * apagada en las pruebas (`TWO_FACTOR_REQUIRED=false`): se enciende donde la invitación debe proceder.
 */

beforeEach(function (): void {
    config(['two_factor.required' => true]);
    Http::preventStrayRequests();
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return list<SendTransactionalMail> */
function riInvitationMails(): array
{
    return Queue::pushed(SendTransactionalMail::class, fn (SendTransactionalMail $job): bool => $job->notificationKey === 'reviewer.invitation')->values()->all();
}

function riTokenFromUrl(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) $query['token'];
}

function riReviewerAssignments(Event $event): int
{
    return EventAssignment::query()->withoutGlobalScopes()->where('event_id', $event->id)->where('role', EventAssignmentRole::Reviewer)->count();
}

it('CA-011-33: an organizer with reviewers.invite creates the account, the reviewer role, the event assignment and one invitation with a password link, audited', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    Queue::fake();

    $outcome = app(InviteReviewer::class)->handle($organizer, $event, ['name' => 'Luis Ramírez', 'email' => 'Luis@Example.test']);

    $reviewer = User::query()->where('email', 'luis@example.test')->firstOrFail();
    expect($outcome)->toBe(ReviewerInvitationOutcome::Invited)
        ->and($reviewer->name)->toBe('Luis Ramírez')
        ->and($reviewer->organizations()->whereKey($organization->id)->wherePivot('status', 'active')->exists())->toBeTrue();

    CurrentOrganization::run($organization, function () use ($event, $reviewer, $organizer, $organization): void {
        expect($reviewer->fresh()->hasRole(OrganizationRole::Reviewer->value))->toBeTrue()
            ->and(EventAssignment::isReviewerOf($event, $reviewer))->toBeTrue();

        $log = Activity::query()->where('event', 'reviewer_invited')->sole();
        expect($log->causer_id)->toBe($organizer->id)
            ->and($log->subject_id)->toBe($reviewer->id)
            ->and($log->properties['event_id'])->toBe($event->id)
            ->and($log->properties['organization_id'])->toBe($organization->id)
            ->and(json_encode($log->properties))->not->toContain('luis@example.test')
            ->and(Activity::query()->where('event', 'event_assignment_created')->count())->toBe(1);
    });

    $mails = riInvitationMails();
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->recipient->email)->toBe('luis@example.test')
        ->and($mails[0]->variables['organization_name'])->toBe($organization->name)
        ->and($mails[0]->variables['expires_in_minutes'])->toBe('60');

    // Mismo mecanismo que `team.set_password`: un token del corredor de contraseñas, válido para esta cuenta.
    $token = riTokenFromUrl($mails[0]->variables['access_url']);
    expect(Password::broker()->tokenExists($reviewer, $token))->toBeTrue();
});

it('CA-011-33: only reviewers.invite can invite: an org admin can, a reviewer and a member of another organization cannot', function (): void {
    [$organization, $event, , $admin] = formScenario();
    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    [, , , $foreignAdmin] = formScenario();
    Queue::fake();

    foreach ([$reviewer, $foreignAdmin] as $denied) {
        expect(fn () => app(InviteReviewer::class)->handle($denied, $event, ['name' => 'Ana', 'email' => 'ana@example.test']))
            ->toThrow(AuthorizationException::class);
    }

    expect(User::query()->where('email', 'ana@example.test')->exists())->toBeFalse()
        ->and(app(InviteReviewer::class)->handle($admin, $event, ['name' => 'Ana', 'email' => 'ana@example.test']))->toBe(ReviewerInvitationOutcome::Invited);
});

it('CA-011-34: with the mandatory 2FA off the invitation is rejected with its cause and no account is created', function (): void {
    config(['two_factor.required' => false]);
    [, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    Queue::fake();

    $thrown = null;
    try {
        app(InviteReviewer::class)->handle($organizer, $event, ['name' => 'Luis', 'email' => 'luis@example.test']);
    } catch (ValidationException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(ValidationException::class)
        ->and($thrown->errors()['email'][0])->toBe(__('reviewer_invitation.errors.two_factor_required'))
        ->and(User::query()->where('email', 'luis@example.test')->exists())->toBeFalse()
        ->and(riReviewerAssignments($event))->toBe(0)
        ->and(riInvitationMails())->toBe([]);
});

it('CA-011-35: an invalid email is rejected with a validation error and nothing is created', function (string $email): void {
    [, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    Queue::fake();

    expect(fn () => app(InviteReviewer::class)->handle($organizer, $event, ['name' => 'Luis', 'email' => $email]))
        ->toThrow(ValidationException::class)
        ->and(riReviewerAssignments($event))->toBe(0)
        ->and(riInvitationMails())->toBe([]);
})->with(['no at sign' => 'luis.example.test', 'empty' => '', 'two at signs' => 'a@b@example.test']);

it('CA-011-35: an event of another organization cannot be invited to: the page answers 404 and the action denies the actor', function (): void {
    // Con la 2FA exigida el panel redirige a configurarla antes de resolver la ruta: aquí se prueba la ruta.
    config(['two_factor.required' => false]);
    [$organization, , $organizer] = formScenario(OrganizationRole::Organizer);
    [, $foreignEvent] = formScenario();
    Queue::fake();

    foreach (['reviewers', 'assignment-rules'] as $page) {
        $this->actingAs($organizer)->get("/admin/{$organization->slug}/events/{$foreignEvent->id}/{$page}")->assertNotFound();
    }

    config(['two_factor.required' => true]);
    CurrentOrganization::forget();
    expect(fn () => app(InviteReviewer::class)->handle($organizer, $foreignEvent, ['name' => 'Luis', 'email' => 'luis@example.test']))
        ->toThrow(AuthorizationException::class)
        ->and(riInvitationMails())->toBe([]);
});

it('CA-011-36: an existing member is not duplicated: gets the event assignment, no password link, and is told so', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $member = Memberships::attach(User::factory()->create(['email' => 'ana@example.test', 'name' => 'Ana']), $organization, OrganizationRole::Reviewer);
    Queue::fake();

    $outcome = app(InviteReviewer::class)->handle($organizer, $event, ['name' => 'Otro nombre', 'email' => 'ANA@example.test']);
    $again = app(InviteReviewer::class)->handle($organizer, $event, ['name' => 'Ana', 'email' => 'ana@example.test']);

    expect($outcome)->toBe(ReviewerInvitationOutcome::AlreadyMember)
        ->and($again)->toBe(ReviewerInvitationOutcome::AlreadyMember)
        ->and(User::query()->where('email', 'ana@example.test')->count())->toBe(1)
        ->and($member->fresh()->name)->toBe('Ana')
        ->and(riReviewerAssignments($event))->toBe(1)
        ->and(riInvitationMails())->toBe([])
        ->and(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('CA-011-36: a deactivated member is rejected and keeps no new assignment', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    Memberships::attach(User::factory()->create(['email' => 'ana@example.test']), $organization, OrganizationRole::Reviewer, 'deactivated');
    Queue::fake();

    expect(fn () => app(InviteReviewer::class)->handle($organizer, $event, ['name' => 'Ana', 'email' => 'ana@example.test']))
        ->toThrow(ValidationException::class)
        ->and(riReviewerAssignments($event))->toBe(0);
});

it('CA-011-36: an account that exists only in another organization is invited without revealing it and never gets a password link', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $outsider = Memberships::attach(User::factory()->create(['email' => 'ana@example.test', 'password' => 'old-password-for-tests']), Memberships::organization(), OrganizationRole::OrgAdmin);
    Queue::fake();

    $existing = app(InviteReviewer::class)->handle($organizer, $event, ['name' => 'Ana', 'email' => 'ana@example.test']);
    $new = app(InviteReviewer::class)->handle($organizer, $event, ['name' => 'Luis', 'email' => 'luis@example.test']);

    $mails = collect(riInvitationMails())->keyBy(fn (SendTransactionalMail $job): string => $job->recipient->email);
    expect($existing)->toBe($new)
        ->and($existing)->toBe(ReviewerInvitationOutcome::Invited)
        ->and($outsider->organizations()->whereKey($organization->id)->exists())->toBeTrue()
        // Para la cuenta que ya tenía dueño no se emite un token de contraseña: el enlace es el de acceso.
        ->and($mails['ana@example.test']->variables['access_url'])->not->toContain('token=')
        ->and($mails['luis@example.test']->variables['access_url'])->toContain('token=')
        ->and(Hash::check('old-password-for-tests', $outsider->fresh()->password))->toBeTrue();
});

it('CA-011-37: an expired or used invitation link is refused with the generic message and a new one can be requested without revealing the email', function (): void {
    [, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    Queue::fake();
    app(InviteReviewer::class)->handle($organizer, $event, ['name' => 'Luis', 'email' => 'luis@example.test']);
    $reviewer = User::query()->where('email', 'luis@example.test')->firstOrFail();
    $url = riInvitationMails()[0]->variables['access_url'];
    $token = riTokenFromUrl($url);
    Filament::setCurrentPanel('admin');
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('0000000000000000000000000000000000A:1')]);

    $this->travel(61)->minutes();
    Livewire::test(ResetPassword::class, ['email' => $reviewer->email, 'token' => $token])
        ->fillForm(['password' => 'a-brand-new-passphrase', 'passwordConfirmation' => 'a-brand-new-passphrase'])
        ->call('resetPassword')
        ->assertNotified(__('access.reset_invalid'));

    // Pedir un enlace nuevo responde igual para un correo registrado y para uno que no existe.
    Notification::fake();
    $sent = __('access.reset_request.sent.title');
    Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'luis@example.test'])->call('request')->assertNotified($sent);
    Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'nobody@example.test'])->call('request')->assertNotified($sent);
});

it('CA-011-37: a link already used is refused with the generic message', function (): void {
    [, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    Queue::fake();
    app(InviteReviewer::class)->handle($organizer, $event, ['name' => 'Luis', 'email' => 'luis@example.test']);
    $reviewer = User::query()->where('email', 'luis@example.test')->firstOrFail();
    $token = riTokenFromUrl(riInvitationMails()[0]->variables['access_url']);
    Filament::setCurrentPanel('admin');
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('0000000000000000000000000000000000A:1')]);

    $submit = fn (string $password) => Livewire::test(ResetPassword::class, ['email' => $reviewer->email, 'token' => $token])
        ->fillForm(['password' => $password, 'passwordConfirmation' => $password])
        ->call('resetPassword');
    $submit('a-brand-new-passphrase');

    $submit('yet-another-passphrase-1')->assertNotified(__('access.reset_invalid'));
    expect(Hash::check('a-brand-new-passphrase', $reviewer->fresh()->password))->toBeTrue();
});

it('CA-011-33: invitations share the team limit of ten per minute and the eleventh answers 429', function (): void {
    [, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    Queue::fake();
    RateLimiter::clear('team-invite:'.$organizer->id);

    for ($i = 1; $i <= 10; $i++) {
        app(InviteReviewer::class)->handle($organizer, $event, ['name' => "Evaluador {$i}", 'email' => "evaluador{$i}@example.test"]);
    }

    expect(fn () => app(InviteReviewer::class)->handle($organizer, $event, ['name' => 'Uno más', 'email' => 'uno@example.test']))
        ->toThrow(ThrottleRequestsException::class)
        ->and(User::query()->where('email', 'uno@example.test')->exists())->toBeFalse();
});

it('reviewer.invitation: is a critical catalog entry whose default template renders in es and en with every variable', function (): void {
    $descriptor = app(NotificationCatalog::class)->get('reviewer.invitation');
    $variables = SampleMailData::for($descriptor);

    expect($descriptor->critical)->toBeTrue()
        ->and($descriptor->variables)->toBe(['reviewer_name', 'organization_name', 'event_name', 'access_url', 'expires_in_minutes']);

    foreach (['es', 'en'] as $locale) {
        $rendered = app(RenderMailTemplate::class)->handle(
            'reviewer.invitation',
            $locale,
            $variables,
            trans('mail_templates.reviewer.invitation.subject', [], $locale),
            trans('mail_templates.reviewer.invitation.body', [], $locale),
        );

        expect($rendered->subject)->toContain($variables['organization_name'])
            ->and($rendered->text)->toContain($variables['access_url'])->toContain($variables['event_name'])->toContain($variables['expires_in_minutes'])
            ->and($rendered->text)->not->toContain('{{');
    }
});
