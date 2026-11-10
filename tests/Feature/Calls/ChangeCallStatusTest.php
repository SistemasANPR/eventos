<?php

declare(strict_types=1);

use App\Actions\Calls\ChangeCallStatus;
use App\Actions\Calls\CreateCallForSubmissions;
use App\Enums\CallState;
use App\Enums\CallStatus;
use App\Enums\OrganizationRole;
use App\Exceptions\Domain\CallStatusTransitionInvalid;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\CallsRelationManager;
use App\Http\Requests\Calls\ChangeCallStatusRequest;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Calls\ResolveCallState;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;
use Tests\Support\SubmissionTypes;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/**
 * Evento en Ciudad de México (UTC-6) con una convocatoria que abre el 2027-01-10 09:00 local y
 * cierra el 2027-02-15 23:00 local (2027-02-16 05:00 UTC), en el estado indicado.
 *
 * @return array{0: Organization, 1: Event, 2: User, 3: CallForSubmissions}
 */
function statusScenario(CallStatus $status = CallStatus::Published): array
{
    // Un escenario anterior de la misma prueba puede haber dejado su organización en contexto.
    CurrentOrganization::forget();
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $event = Event::factory()->create([
        'organization_id' => $organization->id,
        'timezone' => 'America/Mexico_City',
        'locales' => ['es', 'en'],
        'default_locale' => 'es',
    ]);
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, [
        'submission_type_id' => SubmissionTypes::for($event)->id,
        'title' => ['es' => 'Convocatoria de sesiones'],
        'locales' => ['es'],
        'opens_at' => '2027-01-10 09:00',
        'closes_at' => '2027-02-15 23:00',
    ]);
    CurrentOrganization::run($organization, fn () => $call->forceFill(['status' => $status])->save());

    return [$organization, $event, $admin, $call->fresh() ?? $call];
}

it('CA-004-25: pausing a published call in force makes it paused for the public and resuming makes it open again', function (): void {
    [, , $admin, $call] = statusScenario();
    $this->travelTo(CarbonImmutable::parse('2027-02-01 12:00:00', 'UTC'));
    expect(ResolveCallState::for($call))->toBe(CallState::Open);

    $paused = app(ChangeCallStatus::class)->handle($admin, $call, CallStatus::Paused);

    expect($paused->status)->toBe(CallStatus::Paused)
        ->and(ResolveCallState::for($paused->fresh() ?? $paused))->toBe(CallState::Paused);

    $resumed = app(ChangeCallStatus::class)->handle($admin, $paused, CallStatus::Published);

    expect($resumed->status)->toBe(CallStatus::Published)
        ->and(ResolveCallState::for($resumed->fresh() ?? $resumed))->toBe(CallState::Open);
});

it('CA-004-25: closing by hand closes the call for the public even with the dates in force, from published or paused', function (CallStatus $from): void {
    [, , $admin, $call] = statusScenario($from);
    $this->travelTo(CarbonImmutable::parse('2027-02-01 12:00:00', 'UTC'));

    $closed = app(ChangeCallStatus::class)->handle($admin, $call, CallStatus::Closed);

    expect($closed->status)->toBe(CallStatus::Closed)
        ->and(ResolveCallState::for($closed->fresh() ?? $closed))->toBe(CallState::Closed);
})->with(['from published' => [CallStatus::Published], 'from paused' => [CallStatus::Paused]]);

it('CA-004-25: reopening a manually closed call whose dates are still in force makes it open without asking for a date', function (): void {
    [, , $admin, $call] = statusScenario(CallStatus::Closed);
    $this->travelTo(CarbonImmutable::parse('2027-02-01 12:00:00', 'UTC'));

    $reopened = app(ChangeCallStatus::class)->handle($admin, $call, CallStatus::Published);

    expect($reopened->status)->toBe(CallStatus::Published)
        ->and($reopened->closes_at->utc()->format('Y-m-d H:i'))->toBe('2027-02-16 05:00')
        ->and(ResolveCallState::for($reopened->fresh() ?? $reopened))->toBe(CallState::Open);
});

it('CA-004-25: a draft can be neither paused, closed nor reopened and the error carries a stable code', function (CallStatus $target): void {
    [, , $admin, $call] = statusScenario(CallStatus::Draft);

    try {
        app(ChangeCallStatus::class)->handle($admin, $call, $target);
        $this->fail('Se esperaba CallStatusTransitionInvalid.');
    } catch (CallStatusTransitionInvalid $exception) {
        expect($exception->errors())->toHaveKey('status')
            ->and($exception->getMessage())->toContain(CallStatusTransitionInvalid::CODE);
    }

    expect($call->fresh()?->status)->toBe(CallStatus::Draft);
})->with([
    'pause' => [CallStatus::Paused],
    'close' => [CallStatus::Closed],
    'reopen' => [CallStatus::Published],
    'draft again' => [CallStatus::Draft],
]);

it('CA-004-25: refuses transitions that are not offered (same state, pausing a paused or closed call)', function (CallStatus $from, CallStatus $target): void {
    [, , $admin, $call] = statusScenario($from);

    expect(fn () => app(ChangeCallStatus::class)->handle($admin, $call, $target))
        ->toThrow(CallStatusTransitionInvalid::class);

    expect($call->fresh()?->status)->toBe($from);
})->with([
    'published to published' => [CallStatus::Published, CallStatus::Published],
    'paused to paused' => [CallStatus::Paused, CallStatus::Paused],
    'closed to closed' => [CallStatus::Closed, CallStatus::Closed],
    'closed to paused' => [CallStatus::Closed, CallStatus::Paused],
    'published to draft' => [CallStatus::Published, CallStatus::Draft],
    'closed to draft' => [CallStatus::Closed, CallStatus::Draft],
]);

it('CA-004-26: reopening a call whose close already passed without a new close date is refused asking for one', function (CallStatus $from): void {
    [, , $admin, $call] = statusScenario($from);
    $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:00', 'UTC'));

    try {
        app(ChangeCallStatus::class)->handle($admin, $call, CallStatus::Published);
        $this->fail('Se esperaba ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('closes_at');
    }

    expect($call->fresh()?->status)->toBe($from)
        ->and($call->fresh()?->closes_at->utc()->format('Y-m-d H:i'))->toBe('2027-02-16 05:00');
})->with(['from closed' => [CallStatus::Closed], 'from paused' => [CallStatus::Paused]]);

it('CA-004-26: reopening with a new future close date (local time of the event) stores it in UTC and opens the call', function (): void {
    [, , $admin, $call] = statusScenario(CallStatus::Closed);
    $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:00', 'UTC'));

    $reopened = app(ChangeCallStatus::class)->handle($admin, $call, CallStatus::Published, ['closes_at' => '2027-03-20 18:00']);

    expect($reopened->status)->toBe(CallStatus::Published)
        // 18:00 en Ciudad de México (UTC-6) son las 00:00 UTC del día siguiente.
        ->and($reopened->closes_at->utc()->format('Y-m-d H:i'))->toBe('2027-03-21 00:00')
        ->and(ResolveCallState::for($reopened->fresh() ?? $reopened))->toBe(CallState::Open);
});

it('CA-004-26: a new close date that is not in the future or not after the opening is refused', function (string $closes): void {
    [, , $admin, $call] = statusScenario(CallStatus::Closed);
    $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:00', 'UTC'));

    try {
        app(ChangeCallStatus::class)->handle($admin, $call, CallStatus::Published, ['closes_at' => $closes]);
        $this->fail('Se esperaba ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('closes_at');
    }

    expect($call->fresh()?->status)->toBe(CallStatus::Closed);
})->with([
    'in the past' => '2027-02-20 10:00',
    'right now' => '2027-03-01 06:00',
    'malformed' => 'mañana',
]);

it('CA-004-26: a new close date is also validated when the call has not expired, and moves the close', function (): void {
    [, , $admin, $call] = statusScenario(CallStatus::Closed);
    $this->travelTo(CarbonImmutable::parse('2027-02-01 12:00:00', 'UTC'));

    expect(fn () => app(ChangeCallStatus::class)->handle($admin, $call, CallStatus::Published, ['closes_at' => '2027-01-01 10:00']))
        ->toThrow(ValidationException::class);

    $reopened = app(ChangeCallStatus::class)->handle($admin, $call, CallStatus::Published, ['closes_at' => '2027-02-28 10:00']);

    expect($reopened->closes_at->utc()->format('Y-m-d H:i'))->toBe('2027-02-28 16:00');
});

it('CA-004-26: pausing or closing ignores a close date that comes in the data', function (): void {
    [, , $admin, $call] = statusScenario();

    $closed = app(ChangeCallStatus::class)->handle($admin, $call, CallStatus::Closed, ['closes_at' => '2027-02-20 10:00']);

    expect($closed->closes_at->utc()->format('Y-m-d H:i'))->toBe('2027-02-16 05:00');
});

it('CA-004-26: the request rules require the new close date in local format, after the opening and in the future', function (): void {
    [, $event, , $call] = statusScenario(CallStatus::Closed);
    $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:00', 'UTC'));
    $rules = ChangeCallStatusRequest::rulesFor($event, $call);

    expect(Validator::make(['closes_at' => '2027-03-20 18:00'], $rules)->passes())->toBeTrue()
        ->and(Validator::make([], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['closes_at' => '2027-03-01 06:00'], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['closes_at' => '2027-01-10 09:00'], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['closes_at' => '20/03/2027'], $rules)->fails())->toBeTrue();
});

it('CA-004-27: audits who changed the status, when, and the previous and new status', function (): void {
    [, , $admin, $call] = statusScenario();
    $this->travelTo(CarbonImmutable::parse('2027-02-01 12:00:00', 'UTC'));

    app(ChangeCallStatus::class)->handle($admin, $call, CallStatus::Paused);

    $entry = Activity::query()->where('event', 'call_status_changed')->sole();

    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->subject_id)->toBe($call->id)
        ->and($entry->created_at?->utc()->format('Y-m-d H:i'))->toBe('2027-02-01 12:00')
        ->and($entry->properties->get('old'))->toBe(['status' => 'published'])
        ->and($entry->properties->get('new'))->toBe(['status' => 'paused'])
        ->and($entry->properties->get('organization_id'))->toBe($call->organization_id);
});

it('CA-004-27: the audit of a reopening with a new close date also keeps the previous and new close', function (): void {
    [, , $admin, $call] = statusScenario(CallStatus::Closed);
    $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:00', 'UTC'));

    app(ChangeCallStatus::class)->handle($admin, $call, CallStatus::Published, ['closes_at' => '2027-03-20 18:00']);

    $entry = Activity::query()->where('event', 'call_status_changed')->sole();

    expect($entry->properties->get('old'))->toBe(['status' => 'closed', 'closes_at' => '2027-02-16T05:00:00Z'])
        ->and($entry->properties->get('new'))->toBe(['status' => 'published', 'closes_at' => '2027-03-21T00:00:00Z']);
});

it('CA-004-27: a refused change leaves no audit entry', function (): void {
    [, , $admin, $call] = statusScenario(CallStatus::Draft);

    expect(fn () => app(ChangeCallStatus::class)->handle($admin, $call, CallStatus::Closed))
        ->toThrow(CallStatusTransitionInvalid::class);

    expect(Activity::query()->where('event', 'call_status_changed')->count())->toBe(0);
});

it('CA-004-27: a member without calls.manage cannot change the status (403) and nothing changes', function (OrganizationRole $role): void {
    [$organization, , , $call] = statusScenario();
    $member = Memberships::attach(User::factory()->create(), $organization, $role);

    expect(fn () => app(ChangeCallStatus::class)->handle($member, $call, CallStatus::Paused))
        ->toThrow(AuthorizationException::class);

    expect($call->fresh()?->status)->toBe(CallStatus::Published)
        ->and(Activity::query()->where('event', 'call_status_changed')->count())->toBe(0);
})->with(['reviewer' => [OrganizationRole::Reviewer]]);

it('CA-004-27: the policy allows changeStatus to an organizer and denies it to someone who only views calls', function (): void {
    [$organization, , , $call] = statusScenario();
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    $viewer = Memberships::attach(User::factory()->create(), $organization);
    CurrentOrganization::run($organization, fn () => $viewer->givePermissionTo('calls.view'));

    CurrentOrganization::run($organization, function () use ($organizer, $viewer, $call): void {
        $viewer->unsetRelation('permissions')->unsetRelation('roles');

        expect(Gate::forUser($organizer)->allows('changeStatus', $call))->toBeTrue()
            ->and(Gate::forUser($viewer)->allows('changeStatus', $call))->toBeFalse();
    });
});

it('CA-004-27: a deactivated admin cannot change the status', function (): void {
    [$organization, , , $call] = statusScenario();
    $deactivated = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin, 'deactivated');

    expect(fn () => app(ChangeCallStatus::class)->handle($deactivated, $call, CallStatus::Closed))
        ->toThrow(AuthorizationException::class);
});

it('CA-004-27: an admin of another organization cannot change the status of this call', function (): void {
    [, , , $callOfB] = statusScenario();
    [, , $adminA] = statusScenario();

    expect(fn () => app(ChangeCallStatus::class)->handle($adminA, $callOfB, CallStatus::Closed))
        ->toThrow(AuthorizationException::class);

    expect($callOfB->fresh()?->status)->toBe(CallStatus::Published);
});

/** Ruta por la que viajan las acciones Livewire del panel (su prefijo lleva un hash: `livewire-xxxx/update`). */
function livewireUpdateRoute(): ?Illuminate\Routing\Route
{
    return collect(Route::getRoutes()->getRoutes())->first(
        static fn (Illuminate\Routing\Route $route): bool => preg_match('#^livewire[^/]*/update$#', $route->uri()) === 1,
    );
}

it('CA-004-24: no route that changes the status accepts GET, and the Livewire endpoint that carries the panel actions is POST only', function (): void {
    $changers = collect(Route::getRoutes()->getRoutes())->filter(
        static fn (Illuminate\Routing\Route $route): bool => preg_match('/change-?status|ChangeCallStatus/i', $route->uri().'|'.($route->getName() ?? '').'|'.$route->getActionName()) === 1,
    );
    $livewire = livewireUpdateRoute();

    expect($changers->filter(static fn (Illuminate\Routing\Route $route): bool => in_array('GET', $route->methods(), true))->count())->toBe(0)
        ->and($livewire)->toBeInstanceOf(Illuminate\Routing\Route::class)
        ->and($livewire?->methods())->toBe(['POST']);
});

it('CA-004-24: a GET to the Livewire update endpoint answers 405 and changes nothing', function (): void {
    [, , , $call] = statusScenario();
    $update = livewireUpdateRoute();

    $this->get('/'.ltrim((string) $update?->uri(), '/'))->assertStatus(405);

    expect($call->fresh()?->status)->toBe(CallStatus::Published);
});

/** @return array{0: Organization, 1: Event, 2: User, 3: CallForSubmissions} */
function statusPanelScenario(CallStatus $status, OrganizationRole $role = OrganizationRole::OrgAdmin): array
{
    [$organization, $event, $admin, $call] = statusScenario($status);
    $user = $role === OrganizationRole::OrgAdmin ? $admin : Memberships::attach(User::factory()->create(), $organization, $role);

    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return [$organization, $event, $user, $call];
}

/** @return Testable<CallsRelationManager> */
function statusManager(Event $event): Testable
{
    return Livewire::test(CallsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class]);
}

it('CA-004-25: the panel offers pause and close on a published call, and not reopen', function (): void {
    [, $event, , $call] = statusPanelScenario(CallStatus::Published);

    statusManager($event)
        ->assertActionVisible(TestAction::make('pauseCall')->table($call))
        ->assertActionVisible(TestAction::make('closeCall')->table($call))
        ->assertActionHidden(TestAction::make('resumeCall')->table($call))
        ->assertActionHidden(TestAction::make('reopenCall')->table($call));
});

it('CA-004-25: the panel offers resume and close on a paused call, and reopen on a closed one', function (): void {
    [, $event, , $paused] = statusPanelScenario(CallStatus::Paused);

    statusManager($event)
        ->assertActionVisible(TestAction::make('resumeCall')->table($paused))
        ->assertActionVisible(TestAction::make('closeCall')->table($paused))
        ->assertActionHidden(TestAction::make('pauseCall')->table($paused));

    [, $eventB, , $closed] = statusPanelScenario(CallStatus::Closed);

    statusManager($eventB)
        ->assertActionVisible(TestAction::make('reopenCall')->table($closed))
        ->assertActionHidden(TestAction::make('pauseCall')->table($closed))
        ->assertActionHidden(TestAction::make('closeCall')->table($closed));
});

it('CA-004-25: a draft shows none of the status actions', function (): void {
    [, $event, , $call] = statusPanelScenario(CallStatus::Draft);

    statusManager($event)
        ->assertActionHidden(TestAction::make('pauseCall')->table($call))
        ->assertActionHidden(TestAction::make('closeCall')->table($call))
        ->assertActionHidden(TestAction::make('resumeCall')->table($call))
        ->assertActionHidden(TestAction::make('reopenCall')->table($call));
});

it('CA-004-27: a reviewer has no status actions in the panel', function (): void {
    [, $event, , $call] = statusPanelScenario(CallStatus::Published, OrganizationRole::Reviewer);

    expect(Gate::allows('changeStatus', $call))->toBeFalse();
});

it('CA-004-25: an admin pauses, resumes and closes a call from the panel', function (): void {
    [$organization, $event, , $call] = statusPanelScenario(CallStatus::Published);

    statusManager($event)->callAction(TestAction::make('pauseCall')->table($call));
    CurrentOrganization::set($organization);
    expect($call->fresh()?->status)->toBe(CallStatus::Paused);

    statusManager($event)->callAction(TestAction::make('resumeCall')->table($call->fresh() ?? $call));
    CurrentOrganization::set($organization);
    expect($call->fresh()?->status)->toBe(CallStatus::Published);

    statusManager($event)->callAction(TestAction::make('closeCall')->table($call->fresh() ?? $call));
    CurrentOrganization::set($organization);
    expect($call->fresh()?->status)->toBe(CallStatus::Closed);
});

it('CA-004-26: the panel reopen without a new date is refused when the close passed, and with a date it reopens', function (): void {
    [$organization, $event, , $call] = statusPanelScenario(CallStatus::Closed);
    $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:00', 'UTC'));

    statusManager($event)->callAction(TestAction::make('reopenCall')->table($call), ['closes_at' => null]);
    CurrentOrganization::set($organization);
    expect($call->fresh()?->status)->toBe(CallStatus::Closed);

    statusManager($event)->callAction(TestAction::make('reopenCall')->table($call), ['closes_at' => '2027-03-20 18:00']);
    CurrentOrganization::set($organization);
    expect($call->fresh()?->status)->toBe(CallStatus::Published)
        ->and($call->fresh()?->closes_at->utc()->format('Y-m-d H:i'))->toBe('2027-03-21 00:00');
});
