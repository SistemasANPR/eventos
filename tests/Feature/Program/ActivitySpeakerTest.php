<?php

declare(strict_types=1);

use App\Actions\Program\CreateActivity;
use App\Actions\Program\CreateSpeaker;
use App\Actions\Program\DeleteActivity;
use App\Actions\Program\DeleteSpeaker;
use App\Actions\Program\SyncActivitySpeakers;
use App\Actions\Program\SyncSpeakerActivities;
use App\Actions\Program\UpdateActivity;
use App\Actions\Program\UpdateSpeaker;
use App\Enums\OrganizationRole;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity as AuditLog;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

/*
 * Plan 021, F3b: ponentes de una actividad (`activity_speaker`, CA-021-5) y saneado de la descripción.
 */

beforeEach(function (): void {
    Storage::fake(MediaDisk::name());
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array{0: Event, 1: User} evento de una organización nueva y un usuario con el rol indicado en ella */
function pairingScenario(OrganizationRole $role = OrganizationRole::OrgAdmin): array
{
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);

    return [Event::factory()->create(['organization_id' => $organization->id]), $user];
}

function pairingSpeaker(Event $event): Speaker
{
    return CurrentOrganization::run($event->organization, fn (): Speaker => Speaker::factory()->create(['event_id' => $event->id]));
}

function pairingActivity(Event $event): EventActivity
{
    return CurrentOrganization::run($event->organization, fn (): EventActivity => EventActivity::factory()->create(['event_id' => $event->id]));
}

/** @return list<string> ids de los ponentes de la actividad, en el orden asignado */
function pairingSpeakerIds(EventActivity $activity): array
{
    return DB::table('activity_speaker')->where('activity_id', $activity->id)->orderBy('position')->pluck('speaker_id')->all();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pairingActivityData(array $overrides = []): array
{
    return array_replace([
        'title' => 'Apertura',
        'starts_on' => '2027-03-10',
        'ends_on' => '2027-03-10',
        'starts_at' => '09:00',
        'ends_at' => '10:00',
    ], $overrides);
}

// --- Base de datos ---------------------------------------------------------------------------------

it('CA-021-5: the database links speakers and activities of the same event, many to many', function (): void {
    [$event] = pairingScenario();
    $activities = [pairingActivity($event), pairingActivity($event)];
    $speakers = [pairingSpeaker($event), pairingSpeaker($event)];

    foreach ($activities as $activity) {
        foreach ($speakers as $speaker) {
            DB::table('activity_speaker')->insert([
                'activity_id' => $activity->id,
                'speaker_id' => $speaker->id,
                'organization_id' => $event->organization_id,
                'event_id' => $event->id,
            ]);
        }
    }

    expect(DB::table('activity_speaker')->count())->toBe(4);
});

it('CA-021-5: the database refuses a speaker of another event or organization, and a repeated pair', function (): void {
    [$event] = pairingScenario();
    [$otherEvent] = pairingScenario();
    $activity = pairingActivity($event);
    $sibling = pairingSpeaker(Event::factory()->create(['organization_id' => $event->organization_id]));
    $foreign = pairingSpeaker($otherEvent);
    $own = pairingSpeaker($event);

    $row = fn (Speaker $speaker): array => [
        'activity_id' => $activity->id,
        'speaker_id' => $speaker->id,
        'organization_id' => $event->organization_id,
        'event_id' => $event->id,
    ];

    expect(fn () => DB::transaction(fn () => DB::table('activity_speaker')->insert($row($sibling))))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('activity_speaker')->insert($row($foreign))))->toThrow(QueryException::class);

    DB::table('activity_speaker')->insert($row($own));

    expect(fn () => DB::transaction(fn () => DB::table('activity_speaker')->insert($row($own))))->toThrow(QueryException::class);
});

it('CA-021-16: deleting an activity or a speaker cleans the link and deletes neither the other side', function (): void {
    [$event, $admin] = pairingScenario();
    $activity = pairingActivity($event);
    $speaker = pairingSpeaker($event);
    $other = pairingSpeaker($event);

    app(SyncActivitySpeakers::class)->handle($admin, $activity, [$speaker->id, $other->id]);
    app(DeleteSpeaker::class)->handle($admin, $other);
    DB::table('speakers')->where('id', $other->id)->delete();

    expect(pairingSpeakerIds($activity))->toBe([$speaker->id]);

    app(DeleteActivity::class)->handle($admin, $activity);

    expect(CurrentOrganization::run($event->organization, fn () => Speaker::query()->whereKey($speaker->id)->exists()))->toBeTrue();
});

// --- Action de la actividad ------------------------------------------------------------------------

it('CA-021-5: assigns several speakers in the given order, replaces them and accepts none', function (): void {
    [$event, $admin] = pairingScenario();
    $activity = pairingActivity($event);
    [$a, $b, $c] = [pairingSpeaker($event), pairingSpeaker($event), pairingSpeaker($event)];

    app(SyncActivitySpeakers::class)->handle($admin, $activity, [$b->id, $a->id]);
    expect(pairingSpeakerIds($activity))->toBe([$b->id, $a->id]);

    app(SyncActivitySpeakers::class)->handle($admin, $activity, [$c->id, $a->id]);
    expect(pairingSpeakerIds($activity))->toBe([$c->id, $a->id]);

    app(SyncActivitySpeakers::class)->handle($admin, $activity, []);
    expect(pairingSpeakerIds($activity))->toBe([]);
});

it('CA-021-5: a speaker can be in several activities and an activity can have several speakers', function (): void {
    [$event, $admin] = pairingScenario();
    [$first, $second] = [pairingActivity($event), pairingActivity($event)];
    [$a, $b] = [pairingSpeaker($event), pairingSpeaker($event)];

    app(SyncActivitySpeakers::class)->handle($admin, $first, [$a->id, $b->id]);
    app(SyncActivitySpeakers::class)->handle($admin, $second, [$a->id]);

    expect(pairingSpeakerIds($first))->toHaveCount(2)
        ->and(pairingSpeakerIds($second))->toBe([$a->id])
        ->and(CurrentOrganization::run($event->organization, fn () => $a->activities()->count()))->toBe(2);
});

it('CA-021-5: an unpublished speaker can be assigned and the same speaker twice is stored once', function (): void {
    [$event, $admin] = pairingScenario();
    $activity = pairingActivity($event);
    $speaker = CurrentOrganization::run($event->organization, fn () => Speaker::factory()->unpublished()->create(['event_id' => $event->id]));

    app(SyncActivitySpeakers::class)->handle($admin, $activity, [$speaker->id, $speaker->id]);

    expect(pairingSpeakerIds($activity))->toBe([$speaker->id]);
});

it('CA-021-5 CA-021-17: a speaker of another event or organization, or a deleted or malformed id, is refused and nothing changes', function (string $case): void {
    [$event, $admin] = pairingScenario();
    [$otherEvent] = pairingScenario();
    $activity = pairingActivity($event);
    $own = pairingSpeaker($event);
    app(SyncActivitySpeakers::class)->handle($admin, $activity, [$own->id]);

    $candidate = match ($case) {
        'other organization' => pairingSpeaker($otherEvent)->id,
        'same organization, other event' => pairingSpeaker(Event::factory()->create(['organization_id' => $event->organization_id]))->id,
        'deleted' => (function () use ($event, $admin): string {
            $gone = pairingSpeaker($event);
            app(DeleteSpeaker::class)->handle($admin, $gone);

            return $gone->id;
        })(),
        default => 'no-es-un-uuid',
    };

    try {
        app(SyncActivitySpeakers::class)->handle($admin, $activity, [$own->id, $candidate]);
        $this->fail('Debió fallar la validación.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['speaker_ids.1']);
    }

    expect(pairingSpeakerIds($activity))->toBe([$own->id]);
})->with(['other organization', 'same organization, other event', 'deleted', 'malformed']);

it('CA-021-15: only activities.manage assigns speakers; the reviewer and a user of another organization cannot', function (): void {
    [$event] = pairingScenario();
    $activity = pairingActivity($event);
    $speaker = pairingSpeaker($event);
    $reviewer = Memberships::attach(User::factory()->create(), $event->organization, OrganizationRole::Reviewer);
    [, $outsider] = pairingScenario();

    foreach ([$reviewer, $outsider] as $user) {
        expect(fn () => app(SyncActivitySpeakers::class)->handle($user, $activity, [$speaker->id]))->toThrow(AuthorizationException::class);
    }

    expect(pairingSpeakerIds($activity))->toBe([]);
});

it('CA-021-5: assigning speakers is audited with ids only and an unchanged assignment leaves no entry', function (): void {
    [$event, $admin] = pairingScenario();
    $activity = pairingActivity($event);
    $speaker = pairingSpeaker($event);

    app(SyncActivitySpeakers::class)->handle($admin, $activity, [$speaker->id]);
    app(SyncActivitySpeakers::class)->handle($admin, $activity, [$speaker->id]);

    $entries = AuditLog::query()->where('event', 'activity_speakers_synced')->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()?->properties->toArray())->toMatchArray([
            'organization_id' => $event->organization_id,
            'event_id' => $event->id,
            'attached' => [$speaker->id],
            'detached' => [],
        ]);
});

// --- Alta y edición de la actividad ----------------------------------------------------------------

it('CA-021-4 CA-021-5: creating and editing an activity assigns its speakers, and omitting them leaves them as they were', function (): void {
    [$event, $admin] = pairingScenario();
    [$a, $b] = [pairingSpeaker($event), pairingSpeaker($event)];

    $activity = app(CreateActivity::class)->handle($admin, $event, pairingActivityData(['speaker_ids' => [$a->id]]));
    expect(pairingSpeakerIds($activity))->toBe([$a->id]);

    app(UpdateActivity::class)->handle($admin, $activity, pairingActivityData(['title' => 'Otro']));
    expect(pairingSpeakerIds($activity))->toBe([$a->id]);

    app(UpdateActivity::class)->handle($admin, $activity, pairingActivityData(['speaker_ids' => [$b->id, $a->id]]));
    expect(pairingSpeakerIds($activity))->toBe([$b->id, $a->id]);

    app(UpdateActivity::class)->handle($admin, $activity, pairingActivityData(['speaker_ids' => []]));
    expect(pairingSpeakerIds($activity))->toBe([]);
});

it('CA-021-5: a foreign speaker rolls the whole creation back', function (): void {
    [$event, $admin] = pairingScenario();
    [$otherEvent] = pairingScenario();
    $foreign = pairingSpeaker($otherEvent);

    expect(fn () => app(CreateActivity::class)->handle($admin, $event, pairingActivityData(['speaker_ids' => [$foreign->id]])))
        ->toThrow(ValidationException::class);

    expect(CurrentOrganization::run($event->organization, fn () => EventActivity::query()->count()))->toBe(0);
});

// --- Descripción saneada ---------------------------------------------------------------------------

it('CA-021-2: the description of an activity is sanitized when created and when edited', function (): void {
    [$event, $admin] = pairingScenario();
    $dirty = '<p onclick="x()">Hola <strong>mundo</strong></p><script>alert(1)</script><a href="javascript:alert(1)">malo</a>';

    $activity = app(CreateActivity::class)->handle($admin, $event, pairingActivityData(['description' => $dirty]));

    expect($activity->description)->toContain('<strong>mundo</strong>')
        ->and($activity->description)->not->toContain('script')
        ->and($activity->description)->not->toContain('onclick')
        ->and($activity->description)->not->toContain('javascript:');

    app(UpdateActivity::class)->handle($admin, $activity, pairingActivityData(['description' => '<p>Limpio</p><iframe src="https://x.test"></iframe>']));

    expect($activity->description)->toBe('<p>Limpio</p>');
});

it('CA-021-2: a description without visible text is stored as null', function (): void {
    [$event, $admin] = pairingScenario();

    $activity = app(CreateActivity::class)->handle($admin, $event, pairingActivityData(['description' => '<p></p><script>x</script>']));

    expect($activity->description)->toBeNull();
});

// --- Action del ponente ----------------------------------------------------------------------------

it('CA-021-5: from the speaker, assigns activities of the event and keeps the speakers already in them in place', function (): void {
    [$event, $admin] = pairingScenario();
    [$first, $second] = [pairingActivity($event), pairingActivity($event)];
    [$a, $b] = [pairingSpeaker($event), pairingSpeaker($event)];
    app(SyncActivitySpeakers::class)->handle($admin, $first, [$b->id]);

    app(SyncSpeakerActivities::class)->handle($admin, $a, [$first->id, $second->id]);

    expect(pairingSpeakerIds($first))->toBe([$b->id, $a->id])
        ->and(pairingSpeakerIds($second))->toBe([$a->id]);

    app(SyncSpeakerActivities::class)->handle($admin, $a, [$second->id]);

    expect(pairingSpeakerIds($first))->toBe([$b->id])
        ->and(pairingSpeakerIds($second))->toBe([$a->id]);

    app(SyncSpeakerActivities::class)->handle($admin, $a, []);

    expect(pairingSpeakerIds($second))->toBe([]);
});

it('CA-021-5 CA-021-17: from the speaker, an activity of another event or organization is refused', function (string $case): void {
    [$event, $admin] = pairingScenario();
    [$otherEvent] = pairingScenario();
    $speaker = pairingSpeaker($event);
    $foreign = $case === 'other organization'
        ? pairingActivity($otherEvent)
        : pairingActivity(Event::factory()->create(['organization_id' => $event->organization_id]));

    try {
        app(SyncSpeakerActivities::class)->handle($admin, $speaker, [$foreign->id]);
        $this->fail('Debió fallar la validación.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['activity_ids.0']);
    }

    expect(DB::table('activity_speaker')->count())->toBe(0);
})->with(['other organization', 'same organization, other event']);

it('CA-021-15: assigning activities from the speaker needs both speakers.manage and activities.manage', function (): void {
    [$event] = pairingScenario();
    $speaker = pairingSpeaker($event);
    $activity = pairingActivity($event);
    $reviewer = Memberships::attach(User::factory()->create(), $event->organization, OrganizationRole::Reviewer);

    expect(fn () => app(SyncSpeakerActivities::class)->handle($reviewer, $speaker, [$activity->id]))->toThrow(AuthorizationException::class);
});

it('CA-021-5: creating and editing a speaker can assign activities, and omitting them leaves them as they were', function (): void {
    [$event, $admin] = pairingScenario();
    [$first, $second] = [pairingActivity($event), pairingActivity($event)];

    $speaker = app(CreateSpeaker::class)->handle($admin, $event, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'activity_ids' => [$first->id]]);
    expect(pairingSpeakerIds($first))->toBe([$speaker->id]);

    app(UpdateSpeaker::class)->handle($admin, $speaker, ['first_name' => 'Ada B']);
    expect(pairingSpeakerIds($first))->toBe([$speaker->id]);

    app(UpdateSpeaker::class)->handle($admin, $speaker, ['activity_ids' => [$second->id]]);
    expect(pairingSpeakerIds($first))->toBe([])
        ->and(pairingSpeakerIds($second))->toBe([$speaker->id]);
});

it('CA-021-5: a foreign activity rolls the speaker edit back', function (): void {
    [$event, $admin] = pairingScenario();
    [$otherEvent] = pairingScenario();
    $speaker = pairingSpeaker($event);
    $foreign = pairingActivity($otherEvent);

    expect(fn () => app(UpdateSpeaker::class)->handle($admin, $speaker, ['first_name' => 'Cambiado', 'activity_ids' => [$foreign->id]]))
        ->toThrow(ValidationException::class);

    expect(CurrentOrganization::run($event->organization, fn () => Speaker::query()->findOrFail($speaker->id)->first_name))->not->toBe('Cambiado');
});
