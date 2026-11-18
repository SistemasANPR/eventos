<?php

declare(strict_types=1);

use App\Actions\Calls\PublishCall;
use App\Actions\Privacy\CreatePrivacyNoticeDraft;
use App\Actions\Privacy\PublishPrivacyNotice;
use App\Enums\CallStatus;
use App\Enums\OrganizationRole;
use App\Exceptions\Domain\PrivacyNoticeMissing;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Models\SubmissionType;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Evento publicado con los idiomas dados y una convocatoria con los suyos (plan 026, F3).
 *
 * @param  list<string>  $eventLocales
 * @param  list<string>  $callLocales
 * @return array{org: Organization, event: Event, call: CallForSubmissions}
 */
function effectiveScenario(array $eventLocales, array $callLocales, string $callStatus = 'published'): array
{
    $org = Memberships::organization();
    $org->forceFill(['slug' => 'parques'])->save();
    CurrentOrganization::set($org);
    $event = Event::factory()->published()->create([
        'organization_id' => $org->id,
        'slug' => 'congreso',
        'name' => ['es' => 'Congreso', 'en' => 'Congress'],
        'locales' => $eventLocales,
        'default_locale' => $eventLocales[0],
    ]);
    $type = SubmissionType::factory()->create(['organization_id' => $org->id, 'event_id' => $event->id]);
    $factory = CallForSubmissions::factory();
    $call = ($callStatus === 'published' ? $factory->published() : $factory)->create([
        'organization_id' => $org->id,
        'event_id' => $event->id,
        'submission_type_id' => $type->id,
        'slug' => 'ponencias',
        'title' => ['es' => 'Convocatoria', 'en' => 'Call'],
        'locales' => $callLocales,
        'opens_at' => '2027-01-10 15:00:00',
        'closes_at' => '2027-02-15 23:00:00',
    ]);

    return compact('org', 'event', 'call');
}

it('CA-026-10: the public call page shows only the effective languages of the call', function (): void {
    // La convocatoria quedó con en y pt, pero el evento ya solo ofrece es y en: queda en.
    effectiveScenario(['es', 'en'], ['en', 'pt']);

    $this->get('/parques/events/congreso/call/ponencias?lang=pt', ['Accept-Language' => 'pt'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('call.locales', ['en'])
            ->where('call.locale', 'en')
            ->where('call.title', 'Call'));
});

it('CA-026-10: a call whose languages all left the event falls back to the default language of the event', function (): void {
    effectiveScenario(['es'], ['en', 'pt']);

    $this->get('/parques/events/congreso/call/ponencias?lang=en')
        ->assertInertia(fn (Assert $page) => $page
            ->where('call.locales', ['es'])
            ->where('call.locale', 'es')
            ->where('call.title', 'Convocatoria'));
});

it('CA-026-10: publishing requires the privacy notice only in the effective languages', function (): void {
    $ctx = effectiveScenario(['es'], ['es', 'en'], 'draft');
    $admin = Memberships::attach(User::factory()->create(), $ctx['org'], OrganizationRole::OrgAdmin);
    app(PublishPrivacyNotice::class)->handle($admin, app(CreatePrivacyNoticeDraft::class)->handle($admin, $ctx['event'], ['es' => '<p>Aviso</p>']));

    $published = app(PublishCall::class)->handle($admin, $ctx['call']);

    expect($published->status)->toBe(CallStatus::Published);
});

it('CA-026-10: publishing still refuses when the notice lacks an effective language', function (): void {
    $ctx = effectiveScenario(['es', 'en'], ['es', 'en', 'pt'], 'draft');
    $admin = Memberships::attach(User::factory()->create(), $ctx['org'], OrganizationRole::OrgAdmin);
    app(PublishPrivacyNotice::class)->handle($admin, app(CreatePrivacyNoticeDraft::class)->handle($admin, $ctx['event'], ['es' => '<p>Aviso</p>']));

    try {
        app(PublishCall::class)->handle($admin, $ctx['call']);
        $this->fail('Se esperaba PrivacyNoticeMissing.');
    } catch (PrivacyNoticeMissing $exception) {
        // pt no es del evento y no se exige; solo falta en.
        expect($exception->getMessage())->toContain(__('calls.privacy_notice_missing', ['locales' => 'en']));
    }
});

it('CA-026-10: the public privacy notice page offers the languages of the event', function (): void {
    $ctx = effectiveScenario(['es'], ['es']);
    PrivacyNotice::factory()->published()->create(['organization_id' => $ctx['org']->id, 'event_id' => $ctx['event']->id]);

    $this->get('/parques/events/congreso/privacy?lang=en')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('event.locales', ['es'])->where('event.locale', 'es'));
});

it('CA-026-11: with a single language the public pages carry one language, so no selector is shown', function (): void {
    effectiveScenario(['es'], ['es']);

    $this->get('/parques/events/congreso')
        ->assertInertia(fn (Assert $page) => $page->where('event.locales', ['es']));
    $this->get('/parques/events/congreso/call/ponencias')
        ->assertInertia(fn (Assert $page) => $page->where('call.locales', ['es']));
    $this->get('/parques/events/congreso/my-submission/request-link')
        ->assertInertia(fn (Assert $page) => $page->where('event.locales', ['es']));
});
