<?php

declare(strict_types=1);

use App\Actions\Program\FindPublicActivity;
use App\Actions\Program\FindPublicSpeaker;
use App\Enums\EventStatus;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Submission;
use App\Support\Program\PublicActivityData;
use App\Support\Program\PublicSpeakerData;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/public-program-helpers.php';

uses(RefreshDatabase::class);

/*
 * Plan 029, F1: constructores de datos públicos. La forma de las props es una lista cerrada
 * (§4.3) y nunca sale un dato personal ni interno (DA-6).
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array{0: Event, 1: array<string, string>} evento y los valores secretos que jamás deben salir */
function ppDataScenario(): array
{
    $organization = Organization::factory()->create();
    $event = Event::factory()->create(['organization_id' => $organization->id, 'status' => EventStatus::Published]);

    $secrets = CurrentOrganization::run($organization, function () use ($event): array {
        $contact = Contact::factory()->create();
        $submission = Submission::factory()->create(['event_id' => $event->id]);

        return ['contact' => $contact->id, 'submission' => $submission->id];
    });

    return [$event, [
        ...$secrets,
        'email' => 'secreto.personal@example.test',
        'country' => 'ZZ',
        'photo_path' => 'organizations/secreta/events/ruta-de-disco/foto.png',
        'icon_path' => 'organizations/secreta/events/ruta-de-disco/icono.png',
        'image_path' => 'organizations/secreta/events/ruta-de-disco/imagen.png',
        'organization_id' => $organization->id,
        'event_id' => $event->id,
    ]];
}

/** @return list<string> */
function dataLeaves(mixed $value): array
{
    if (is_array($value)) {
        return array_merge(...array_map(dataLeaves(...), $value)) ?: [];
    }

    return [(string) $value];
}

it('CA-029-7: speaker and activity props have exactly the closed key list and no personal or internal value', function (): void {
    [$event, $secrets] = ppDataScenario();
    $speaker = ppSpeaker($event, [
        'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => $secrets['email'], 'country' => $secrets['country'],
        'company' => 'Analytical SA', 'job_title' => 'Matematica', 'tagline' => 'Pionera', 'bio' => '<p>Biografia <strong>larga</strong></p>',
        'photo_path' => $secrets['photo_path'], 'contact_id' => $secrets['contact'], 'created_by_submission_id' => $secrets['submission'],
        'social_links' => [['network' => 'linkedin', 'url' => 'https://example.test/ada']],
    ]);
    $activity = ppActivity($event, [
        'title' => 'Motor analitico', 'summary' => 'Resumen', 'description' => '<p>Descripcion</p>',
        'icon_path' => $secrets['icon_path'], 'image_path' => $secrets['image_path'], 'submission_id' => $secrets['submission'],
    ]);
    ppAssign($activity, [$speaker]);
    ppTag($activity, ppCategory($event, 'Charla'));

    $profile = ppIn($event, fn () => app(FindPublicSpeaker::class)->handle($event, $speaker->id));
    $found = ppIn($event, fn () => app(FindPublicActivity::class)->handle($event, $activity->id));

    [$speakerProps, $summaryProps, $activityProps, $activitySummaryProps] = ppIn($event, fn (): array => [
        PublicSpeakerData::detail($profile, $event),
        PublicSpeakerData::summary($profile, $event),
        PublicActivityData::detail($found, $event),
        PublicActivityData::summary($found, $event),
    ]);

    $speakerKeys = ['id', 'name', 'company', 'jobTitle', 'tagline', 'photoUrl', 'url'];
    // Plan 035: el resumen gana `limitedSeats` y el detalle gana cupo, lugar, reunión, transporte y responsables públicos.
    $activityKeys = ['id', 'title', 'summary', 'date', 'endDate', 'startTime', 'endTime', 'categories', 'speakers', 'iconUrl', 'limitedSeats', 'url'];

    expect(array_keys($summaryProps))->toBe($speakerKeys)
        ->and(array_keys($speakerProps))->toBe([...$speakerKeys, 'bioHtml', 'categories', 'socialLinks'])
        ->and(array_keys($activitySummaryProps))->toBe($activityKeys)
        ->and(array_keys($activityProps))->toBe([...$activityKeys, 'descriptionHtml', 'imageUrl', 'seats', 'place', 'meetingPoint', 'transport', 'contacts'])
        ->and(array_keys($activityProps['categories'][0]))->toBe(['id', 'name'])
        ->and(array_keys($activityProps['speakers'][0]))->toBe($speakerKeys)
        ->and(array_keys($speakerProps['socialLinks'][0]))->toBe(['network', 'url']);

    $emitted = json_encode([$speakerProps, $summaryProps, $activityProps, $activitySummaryProps], JSON_THROW_ON_ERROR);

    foreach (['email', 'country', 'photo_path', 'icon_path', 'image_path', 'contact', 'submission', 'organization_id', 'event_id'] as $secret) {
        expect($emitted)->not->toContain($secrets[$secret]);
    }
    expect($emitted)->not->toContain('organizations/secreta');
});

it('CA-029-7: values are formatted as the contract says and the image URLs are the public routes', function (): void {
    [$event, $secrets] = ppDataScenario();
    $organization = $event->organization;
    $speaker = ppSpeaker($event, ['first_name' => ' Ada ', 'last_name' => 'Lovelace', 'photo_path' => $secrets['photo_path'], 'bio' => null]);
    $activity = ppActivity($event, [
        'starts_on' => '2027-03-20', 'ends_on' => '2027-03-21', 'starts_at' => '22:00:00', 'ends_at' => '02:30:00',
        'icon_path' => $secrets['icon_path'], 'image_path' => null, 'summary' => null, 'description' => null,
    ]);
    ppAssign($activity, [$speaker]);

    $found = ppIn($event, fn () => app(FindPublicActivity::class)->handle($event, $activity->id));
    $props = ppIn($event, fn (): array => PublicActivityData::detail($found, $event));

    expect($props['date'])->toBe('2027-03-20')
        ->and($props['endDate'])->toBe('2027-03-21')
        ->and($props['startTime'])->toBe('22:00')
        ->and($props['endTime'])->toBe('02:30')
        ->and($props['summary'])->toBeNull()
        ->and($props['descriptionHtml'])->toBeNull()
        ->and($props['imageUrl'])->toBeNull()
        ->and($props['iconUrl'])->toBe(route('public.activities.media', ['organization' => $organization->slug, 'event' => $event->slug, 'activity' => $activity->id, 'type' => 'icon'], false))
        ->and($props['url'])->toBe("/{$organization->slug}/events/{$event->slug}/activities/{$activity->id}")
        ->and($props['speakers'][0]['name'])->toBe('Ada Lovelace')
        ->and($props['speakers'][0]['photoUrl'])->toBe(route('public.speakers.photo', ['organization' => $organization->slug, 'event' => $event->slug, 'speaker' => $speaker->id], false))
        ->and($props['speakers'][0]['url'])->toBe("/{$organization->slug}/events/{$event->slug}/speakers/{$speaker->id}");
});

it('CA-029-7: a speaker or activity without image has no image URL', function (): void {
    [$event] = ppDataScenario();
    $speaker = ppSpeaker($event, ['photo_path' => null]);

    $found = ppIn($event, fn () => app(FindPublicSpeaker::class)->handle($event, $speaker->id));

    expect(ppIn($event, fn (): ?string => PublicSpeakerData::summary($found, $event)['photoUrl']))->toBeNull();
});

it('CA-029-16: the biography and the description are sanitized again and only https social networks are emitted', function (): void {
    [$event] = ppDataScenario();
    $dirty = '<p>Hola</p><script>alert(1)</script><a href="javascript:alert(2)">malo</a><a href="https://ok.example.test">bueno</a><img src="x" onerror="alert(3)">';
    $speaker = ppSpeaker($event, ['bio' => $dirty, 'social_links' => [
        ['network' => 'web', 'url' => 'https://seguro.example.test/perfil'],
        ['network' => 'viejo', 'url' => 'http://inseguro.example.test'],
        ['network' => 'js', 'url' => 'javascript:alert(1)'],
        ['network' => 'data', 'url' => 'data:text/html,hola'],
        ['network' => 'ftp', 'url' => 'ftp://example.test/archivo'],
        ['network' => 'roto', 'url' => 'https://'],
        ['network' => 'vacio', 'url' => ''],
    ]]);
    $activity = ppActivity($event, ['description' => $dirty]);
    ppAssign($activity, [$speaker]);

    $profile = ppIn($event, fn () => app(FindPublicSpeaker::class)->handle($event, $speaker->id));
    $found = ppIn($event, fn () => app(FindPublicActivity::class)->handle($event, $activity->id));
    $speakerProps = ppIn($event, fn (): array => PublicSpeakerData::detail($profile, $event));
    $activityProps = ppIn($event, fn (): array => PublicActivityData::detail($found, $event));

    foreach ([$speakerProps['bioHtml'], $activityProps['descriptionHtml']] as $html) {
        expect($html)->toContain('<p>Hola</p>')
            ->and($html)->toContain('rel="noopener noreferrer nofollow"')
            ->and($html)->not->toContain('script')
            ->and($html)->not->toContain('javascript:')
            ->and($html)->not->toContain('onerror')
            ->and($html)->not->toContain('<img');
    }
    expect($speakerProps['socialLinks'])->toBe([['network' => 'web', 'url' => 'https://seguro.example.test/perfil']]);
});

it('CA-029-16: a biography that is only markup becomes null', function (): void {
    [$event] = ppDataScenario();
    $speaker = ppSpeaker($event, ['bio' => '<p></p><script>alert(1)</script>']);

    $profile = ppIn($event, fn () => app(FindPublicSpeaker::class)->handle($event, $speaker->id));

    expect(ppIn($event, fn (): ?string => PublicSpeakerData::detail($profile, $event)['bioHtml']))->toBeNull();
});

it('CA-029-7: the builders refuse to run without organization in context', function (): void {
    [$event] = ppDataScenario();
    $speaker = ppSpeaker($event);
    $profile = ppIn($event, fn () => app(FindPublicSpeaker::class)->handle($event, $speaker->id));
    CurrentOrganization::forget();

    expect(fn () => PublicSpeakerData::summary($profile, $event))->toThrow(MissingOrganizationContext::class);
});
