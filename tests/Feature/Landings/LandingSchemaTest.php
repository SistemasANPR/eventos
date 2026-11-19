<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventLanding;
use App\Models\LandingImage;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Inserta una landing cruda para probar las restricciones sin pasar por el modelo.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertLanding(Event $event, array $overrides = []): string
{
    $id = (string) Str::uuid();

    DB::table('event_landings')->insert(array_replace([
        'id' => $id,
        'organization_id' => $event->organization_id,
        'event_id' => $event->id,
        'draft_blocks' => '[]',
        'published_blocks' => null,
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $id;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertLandingImage(string $landingId, Event $event, array $overrides = []): void
{
    DB::table('landing_images')->insert(array_replace([
        'id' => (string) Str::uuid(),
        'organization_id' => $event->organization_id,
        'event_id' => $event->id,
        'event_landing_id' => $landingId,
        'path' => 'organizations/x/events/y/media/landing/a.png',
        'mime' => 'image/png',
        'size_bytes' => 10,
        'width' => 1,
        'height' => 1,
        'created_at' => now(),
    ], $overrides));
}

it('CA-022-3: the database refuses a landing with the organization of A and the event of B', function (): void {
    $organizationA = Organization::factory()->create();
    $eventOfB = Event::factory()->create();

    expect(fn () => DB::transaction(fn () => insertLanding($eventOfB, ['organization_id' => $organizationA->id])))
        ->toThrow(QueryException::class);
});

it('CA-022-3: the database refuses an image with the organization of A and the event or landing of B', function (): void {
    $eventOfA = Event::factory()->create();
    $landingOfA = insertLanding($eventOfA);
    $eventOfB = Event::factory()->create();
    $landingOfB = insertLanding($eventOfB);

    // Evento de B con organización de A.
    expect(fn () => DB::transaction(fn () => insertLandingImage($landingOfA, $eventOfB, ['organization_id' => $eventOfA->organization_id])))
        ->toThrow(QueryException::class)
        // Landing de B con evento y organización de A.
        ->and(fn () => DB::transaction(fn () => insertLandingImage($landingOfB, $eventOfA)))
        ->toThrow(QueryException::class)
        // Landing de otro evento de la misma organización.
        ->and(fn () => DB::transaction(function () use ($eventOfA): void {
            $sibling = Event::factory()->create(['organization_id' => $eventOfA->organization_id]);
            insertLandingImage(insertLanding($sibling), $eventOfA);
        }))
        ->toThrow(QueryException::class);
});

it('CA-022-3: a landing and an image of the same event and organization are accepted', function (): void {
    $event = Event::factory()->create();
    insertLandingImage(insertLanding($event), $event);

    expect(DB::table('event_landings')->count())->toBe(1)
        ->and(DB::table('landing_images')->count())->toBe(1);
});

it('CA-022-1: an event has a single landing', function (): void {
    $event = Event::factory()->create();
    insertLanding($event);

    expect(fn () => DB::transaction(fn () => insertLanding($event)))->toThrow(QueryException::class);
});

it('CA-022-9: the database accepts arrays of blocks and refuses anything else', function (string $draft): void {
    $event = Event::factory()->create();

    expect(fn () => DB::transaction(fn () => insertLanding($event, ['draft_blocks' => $draft])))->toThrow(QueryException::class);
})->with([
    'an object' => ['{"a": 1}'],
    'a string' => ['"hola"'],
    'a number' => ['7'],
]);

it('CA-022-10: the database refuses more than 30 blocks and more than 128 KB, in the draft and in the published version', function (): void {
    $event = Event::factory()->create();
    $thirtyOne = json_encode(array_fill(0, 31, ['a' => 1]), JSON_THROW_ON_ERROR);
    $large = json_encode([['text' => str_repeat('a', 131_100)]], JSON_THROW_ON_ERROR);

    foreach ([$thirtyOne, $large] as $payload) {
        expect(fn () => DB::transaction(fn () => insertLanding($event, ['draft_blocks' => $payload])))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => insertLanding($event, ['published_blocks' => $payload, 'published_at' => now()])))->toThrow(QueryException::class);
    }

    expect(fn () => DB::transaction(fn () => insertLanding($event, ['published_blocks' => '{"a": 1}', 'published_at' => now()])))->toThrow(QueryException::class);
});

it('CA-022-16: the published version and its date go together in the database', function (): void {
    $event = Event::factory()->create();

    expect(fn () => DB::transaction(fn () => insertLanding($event, ['published_blocks' => '[]'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => insertLanding($event, ['published_at' => now()])))->toThrow(QueryException::class);

    insertLanding($event, ['published_blocks' => '[]', 'published_at' => now()]);

    expect(DB::table('event_landings')->count())->toBe(1);
});

it('CA-022-5: the models of the landing are scoped to the organization and soft deleted', function (): void {
    $organization = Organization::factory()->create();
    CurrentOrganization::set($organization);

    $landing = EventLanding::factory()->create();
    $image = LandingImage::factory()->create(['event_landing_id' => $landing->id]);

    expect($landing->organization_id)->toBe($organization->id)
        ->and($image->organization_id)->toBe($organization->id)
        ->and($image->event_id)->toBe($landing->event_id);

    $image->delete();

    expect(LandingImage::query()->count())->toBe(0)
        ->and(LandingImage::query()->withTrashed()->count())->toBe(1);
});
