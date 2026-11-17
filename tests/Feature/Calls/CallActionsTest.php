<?php

declare(strict_types=1);

use App\Actions\Calls\CreateCallForSubmissions;
use App\Actions\Calls\PublishCall;
use App\Actions\Calls\UpdateCallForSubmissions;
use App\Actions\Privacy\CreatePrivacyNoticeDraft;
use App\Actions\Privacy\PublishPrivacyNotice;
use App\Enums\CallState;
use App\Enums\CallStatus;
use App\Enums\OrganizationRole;
use App\Exceptions\Domain\PrivacyNoticeMissing;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Calls\ResolveCallState;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;
use Tests\Support\SubmissionTypes;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Datos del formulario: las fechas viajan como hora LOCAL del evento (`Y-m-d H:i`) y el tipo es el «Sesión» del evento.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function callData(Event $event, array $overrides = []): array
{
    return array_merge([
        'submission_type_id' => SubmissionTypes::for($event)->id,
        'title' => ['es' => 'Convocatoria de sesiones', 'en' => 'Call for sessions'],
        'description' => ['es' => 'Envía tu propuesta'],
        'locales' => ['es', 'en'],
        'opens_at' => '2027-01-10 09:00',
        'closes_at' => '2027-02-15 23:00',
        'results_at' => '2027-03-01 12:00',
    ], $overrides);
}

/** @return array{0: Organization, 1: Event, 2: User} organización con roles, evento en Ciudad de México (es, en) y administrador */
function callScenario(): array
{
    $organization = Memberships::organization();
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $event = Event::factory()->create([
        'organization_id' => $organization->id,
        'timezone' => 'America/Mexico_City',
        'locales' => ['es', 'en'],
        'default_locale' => 'es',
    ]);

    return [$organization, $event, $admin];
}

/**
 * Publica el aviso de privacidad del evento con texto en los idiomas indicados.
 *
 * @param  array<string, string>  $body
 */
function publishNoticeFor(Event $event, User $admin, array $body): void
{
    app(PublishPrivacyNotice::class)->handle($admin, app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, $body));
}

it('CA-004-1: creates a draft call with the dates stored in UTC from the local time of the event', function (): void {
    [, $event, $admin] = callScenario();

    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event));

    expect($call->status)->toBe(CallStatus::Draft)
        ->and($call->event_id)->toBe($event->id)
        ->and($call->organization_id)->toBe($event->organization_id)
        // 09:00 en Ciudad de México (UTC-6) son las 15:00 UTC.
        ->and($call->opens_at->utc()->format('Y-m-d H:i'))->toBe('2027-01-10 15:00')
        ->and($call->closes_at->utc()->format('Y-m-d H:i'))->toBe('2027-02-16 05:00')
        ->and($call->results_at?->utc()->format('Y-m-d H:i'))->toBe('2027-03-01 18:00')
        ->and($call->getTranslation('title', 'en'))->toBe('Call for sessions');
});

it('CA-004-2: refuses a close that is not after the opening, naming the field', function (string $closes): void {
    [, $event, $admin] = callScenario();

    try {
        app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event, ['closes_at' => $closes]));
        $this->fail('Se esperaba ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('closes_at');
    }
})->with(['equal' => '2027-01-10 09:00', 'earlier' => '2027-01-09 09:00']);

it('CA-004-2: refuses results before the close and accepts results left empty', function (): void {
    [, $event, $admin] = callScenario();

    try {
        app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event, ['results_at' => '2027-02-01 09:00']));
        $this->fail('Se esperaba ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('results_at');
    }

    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event, ['results_at' => null]));

    expect($call->results_at)->toBeNull();
});

it('CA-004-3: refuses a language the event does not have and an empty list', function (array $locales): void {
    [, $event, $admin] = callScenario();

    try {
        app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event, ['locales' => $locales]));
        $this->fail('Se esperaba ValidationException.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toContain(...array_filter(['locales', 'locales.1'], fn (string $key): bool => isset($exception->errors()[$key])));
        expect($exception->errors())->not->toBeEmpty();
    }
})->with(['language the event lacks' => [['es', 'pt']], 'no language' => [[]]]);

it('CA-004-4: refuses an unknown submission type', function (): void {
    [$organization, $event, $admin] = callScenario();

    try {
        app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event, ['submission_type_id' => '00000000-0000-7000-8000-000000000000']));
        $this->fail('Se esperaba ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('submission_type_id');
    }

});

it('CA-004-28: the URL identifier is generated from the title in the default language of the event', function (): void {
    [, $event, $admin] = callScenario();

    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event, ['title' => ['en' => 'Call for sessions', 'es' => '¡Convocatoria: Ñandú & Café 2027!']]));

    expect($call->slug)->toBe('convocatoria-nandu-cafe-2027');
});

it('CA-004-28: with no text in the default language it uses another language, and with nothing sluggable a generic name', function (): void {
    [, $event, $admin] = callScenario();

    $english = app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event, ['title' => ['en' => 'Call for posters']]));
    $symbols = app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event, ['title' => ['es' => '¿¿??']]));

    expect($english->slug)->toBe('call-for-posters')->and($symbols->slug)->toBe('convocatoria');
});

it('CA-004-28: a repeated title in the same event gets a numeric suffix and editing never changes the URL', function (): void {
    [, $event, $admin] = callScenario();

    $first = app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event));
    $second = app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event));
    $third = app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event));
    $edited = app(UpdateCallForSubmissions::class)->handle($admin, $second, callData($event, ['title' => ['es' => 'Otro título']]));

    expect($first->slug)->toBe('convocatoria-de-sesiones')
        ->and($second->slug)->toBe('convocatoria-de-sesiones-2')
        ->and($third->slug)->toBe('convocatoria-de-sesiones-3')
        ->and($edited->slug)->toBe('convocatoria-de-sesiones-2');
});

it('CA-004-28: a deleted call still holds its URL, so the next one does not reuse it', function (): void {
    [, $event, $admin] = callScenario();
    $first = app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event));
    $first->delete();

    $next = app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event));

    expect($next->slug)->toBe('convocatoria-de-sesiones-2');
});

it('CA-004-28: the same title is allowed in another event and in another organization', function (): void {
    [$organization, $event, $admin] = callScenario();
    app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event));

    $otherEvent = Event::factory()->create(['organization_id' => $organization->id, 'locales' => ['es'], 'default_locale' => 'es']);
    $organizationB = Memberships::organization();
    $adminB = Memberships::attach(User::factory()->create(), $organizationB, OrganizationRole::OrgAdmin);
    $eventOfB = Event::factory()->create(['organization_id' => $organizationB->id, 'locales' => ['es', 'en'], 'default_locale' => 'es']);

    expect(app(CreateCallForSubmissions::class)->handle($admin, $otherEvent, callData($otherEvent, ['locales' => ['es']])))->toBeInstanceOf(CallForSubmissions::class)
        ->and(app(CreateCallForSubmissions::class)->handle($adminB, $eventOfB, callData($eventOfB)))->toBeInstanceOf(CallForSubmissions::class);
});

it('CA-004-5: a member without calls.manage cannot create, edit or publish', function (): void {
    [$organization, $event, $admin] = callScenario();
    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event));

    expect(fn () => app(CreateCallForSubmissions::class)->handle($reviewer, $event, callData($event, ['title' => ['es' => 'Otra']])))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateCallForSubmissions::class)->handle($reviewer, $call, callData($event, ['title' => ['es' => 'Cambio']])))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(PublishCall::class)->handle($reviewer, $call))
        ->toThrow(AuthorizationException::class);

    expect($call->fresh()->slug)->toBe('convocatoria-de-sesiones')->and($call->fresh()->status)->toBe(CallStatus::Draft);
});

it('CA-004-5: someone who only has calls.view can see calls but not manage them', function (): void {
    [$organization, $event, $admin] = callScenario();
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event));
    $viewer = Memberships::attach(User::factory()->create(), $organization);
    CurrentOrganization::run($organization, fn () => $viewer->givePermissionTo('calls.view'));

    CurrentOrganization::run($organization, function () use ($viewer, $call, $event): void {
        $viewer->unsetRelation('permissions')->unsetRelation('roles');

        expect(Gate::forUser($viewer)->allows('viewAny', CallForSubmissions::class))->toBeTrue()
            ->and(Gate::forUser($viewer)->allows('view', $call))->toBeTrue()
            ->and(Gate::forUser($viewer)->allows('create', [CallForSubmissions::class, $event]))->toBeFalse()
            ->and(Gate::forUser($viewer)->allows('update', $call))->toBeFalse()
            ->and(Gate::forUser($viewer)->allows('publish', $call))->toBeFalse();
    });
});

it('CA-004-5: an organizer can manage calls and a deactivated admin cannot', function (): void {
    [$organization, $event, $admin] = callScenario();
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    $deactivated = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin, 'deactivated');

    expect(app(CreateCallForSubmissions::class)->handle($organizer, $event, callData($event)))->toBeInstanceOf(CallForSubmissions::class)
        ->and(fn () => app(CreateCallForSubmissions::class)->handle($deactivated, $event, callData($event, ['title' => ['es' => 'Otra']])))
        ->toThrow(AuthorizationException::class);
});

it('CA-004-6: an admin of A cannot create a call on an event of B', function (): void {
    [, , $adminA] = callScenario();
    $organizationB = Memberships::organization();
    $eventOfB = Event::factory()->create(['organization_id' => $organizationB->id, 'locales' => ['es', 'en'], 'default_locale' => 'es']);

    expect(fn () => app(CreateCallForSubmissions::class)->handle($adminA, $eventOfB, callData($eventOfB)))
        ->toThrow(AuthorizationException::class);

    expect(CurrentOrganization::run($organizationB, fn () => CallForSubmissions::query()->count()))->toBe(0);
});

it('CA-004-6: a call of B answers nothing when looked up from the context of A', function (): void {
    [$organizationA] = callScenario();
    [$organizationB, $eventB, $adminB] = callScenario();
    $callOfB = app(CreateCallForSubmissions::class)->handle($adminB, $eventB, callData($eventB));

    CurrentOrganization::run($organizationA, function () use ($callOfB): void {
        expect(CallForSubmissions::query()->find($callOfB->id))->toBeNull();
    });
});

it('CA-004-6: an admin of A cannot edit or publish a call of B', function (): void {
    [, , $adminA] = callScenario();
    [$organizationB, $eventB, $adminB] = callScenario();
    $callOfB = app(CreateCallForSubmissions::class)->handle($adminB, $eventB, callData($eventB));

    expect(fn () => app(UpdateCallForSubmissions::class)->handle($adminA, $callOfB, callData($eventB, ['title' => ['es' => 'Robado']])))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(PublishCall::class)->handle($adminA, $callOfB))
        ->toThrow(AuthorizationException::class);
});

it('CA-004-7: audits who created, edited and published with the previous and new values', function (): void {
    [$organization, $event, $admin] = callScenario();
    publishNoticeFor($event, $admin, ['es' => '<p>Aviso</p>', 'en' => '<p>Notice</p>']);
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event));

    app(UpdateCallForSubmissions::class)->handle($admin, $call, callData($event, ['closes_at' => '2027-02-20 23:00']));
    app(PublishCall::class)->handle($admin, $call->fresh());

    $created = Activity::query()->where('event', 'call_created')->sole();
    $updated = Activity::query()->where('event', 'call_updated')->sole();
    $published = Activity::query()->where('event', 'call_published')->sole();

    expect($created->causer_id)->toBe($admin->id)
        ->and($updated->causer_id)->toBe($admin->id)
        ->and($updated->properties->get('old'))->toHaveKey('closes_at')
        ->and($updated->properties->get('new'))->toHaveKey('closes_at')
        ->and($updated->properties->get('old')['closes_at'])->not->toBe($updated->properties->get('new')['closes_at'])
        ->and($published->properties->get('old'))->toBe(['status' => 'draft'])
        ->and($published->properties->get('new'))->toBe(['status' => 'published']);
});

it('CA-004-9: publishes a draft when the event has its privacy notice published in every language of the call', function (): void {
    [, $event, $admin] = callScenario();
    publishNoticeFor($event, $admin, ['es' => '<p>Aviso</p>', 'en' => '<p>Notice</p>']);
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event));

    $published = app(PublishCall::class)->handle($admin, $call);

    expect($published->status)->toBe(CallStatus::Published);
});

it('CA-004-10: refuses to publish without the privacy notice in some language of the call and stays in draft', function (): void {
    [, $event, $admin] = callScenario();
    publishNoticeFor($event, $admin, ['es' => '<p>Solo español</p>']);
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event));

    try {
        app(PublishCall::class)->handle($admin, $call);
        $this->fail('Se esperaba PrivacyNoticeMissing.');
    } catch (PrivacyNoticeMissing $exception) {
        expect($exception->errors())->toHaveKey('status')
            ->and($exception->getMessage())->toContain(PrivacyNoticeMissing::CODE);
    }

    expect($call->fresh()->status)->toBe(CallStatus::Draft);
});

it('CA-004-10: refuses to publish when the event has no published privacy notice at all', function (): void {
    [, $event, $admin] = callScenario();
    app(CreatePrivacyNoticeDraft::class)->handle($admin, $event, ['es' => '<p>Solo borrador</p>', 'en' => '<p>Draft</p>']);
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event));

    expect(fn () => app(PublishCall::class)->handle($admin, $call))->toThrow(PrivacyNoticeMissing::class);
});

it('CA-004-10: a call in one language only needs the notice in that language', function (): void {
    [, $event, $admin] = callScenario();
    publishNoticeFor($event, $admin, ['es' => '<p>Solo español</p>']);
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event, ['locales' => ['es']]));

    expect(app(PublishCall::class)->handle($admin, $call)->status)->toBe(CallStatus::Published);
});

it('CA-004-9: only a draft can be published', function (): void {
    [, $event, $admin] = callScenario();
    publishNoticeFor($event, $admin, ['es' => '<p>Aviso</p>', 'en' => '<p>Notice</p>']);
    $call = app(PublishCall::class)->handle($admin, app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event)));

    expect(fn () => app(PublishCall::class)->handle($admin, $call))->toThrow(ValidationException::class);
});

it('CA-004-11: moving the close to now closes the call for the public on the next request', function (): void {
    [$organization, $event, $admin] = callScenario();
    publishNoticeFor($event, $admin, ['es' => '<p>Aviso</p>', 'en' => '<p>Notice</p>']);
    $call = app(PublishCall::class)->handle($admin, app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event)));

    $this->travelTo(CarbonImmutable::parse('2027-02-01 12:00:00', 'UTC'));
    expect(ResolveCallState::for($call->fresh()))->toBe(CallState::Open);

    // Cierre de emergencia: `closes_at` pasa al instante actual (hora local de Ciudad de México).
    app(UpdateCallForSubmissions::class)->handle($admin, $call->fresh(), callData($event, ['closes_at' => '2027-02-01 06:00']));

    expect(ResolveCallState::for($call->fresh()))->toBe(CallState::Closed);
});

it('CA-004-11: a close at or before the opening is refused by the date rule', function (): void {
    [, $event, $admin] = callScenario();
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event));

    expect(fn () => app(UpdateCallForSubmissions::class)->handle($admin, $call, callData($event, ['closes_at' => '2027-01-10 09:00'])))
        ->toThrow(ValidationException::class);
});

it('CA-004-1: editing keeps the event and validates like creating, and the URL does not change', function (): void {
    [, $event, $admin] = callScenario();
    $call = app(CreateCallForSubmissions::class)->handle($admin, $event, callData($event));

    $updated = app(UpdateCallForSubmissions::class)->handle($admin, $call, callData($event, ['title' => ['es' => 'Título nuevo']]));

    expect($updated->event_id)->toBe($event->id)
        ->and($updated->getTranslation('title', 'es'))->toBe('Título nuevo')
        ->and($updated->slug)->toBe('convocatoria-de-sesiones');
});
