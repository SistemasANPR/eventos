<?php

declare(strict_types=1);

use App\Models\ActivityCategory;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\Submission;
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
 * Inserta una actividad cruda para probar los CHECK sin pasar por el modelo.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertActivity(Event $event, array $overrides = []): void
{
    DB::table('event_activities')->insert(array_replace([
        'id' => (string) Str::uuid(),
        'organization_id' => $event->organization_id,
        'event_id' => $event->id,
        'title' => 'Charla',
        'starts_on' => '2027-03-10',
        'ends_on' => '2027-03-10',
        'starts_at' => '10:00:00',
        'ends_at' => '11:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('CA-021-4: the database accepts an activity with the four schedule fields or with none', function (): void {
    $event = Event::factory()->create();

    insertActivity($event);
    insertActivity($event, ['starts_on' => null, 'ends_on' => null, 'starts_at' => null, 'ends_at' => null]);

    expect(DB::table('event_activities')->count())->toBe(2);
});

it('CA-021-4: the database refuses a partial schedule', function (array $partial): void {
    $event = Event::factory()->create();

    expect(fn () => DB::transaction(fn () => insertActivity($event, $partial)))->toThrow(QueryException::class);
})->with([
    'only the start date' => [['ends_on' => null, 'starts_at' => null, 'ends_at' => null]],
    'only the times' => [['starts_on' => null, 'ends_on' => null]],
    'the end time missing' => [['ends_at' => null]],
    'the end date missing' => [['ends_on' => null]],
]);

it('CA-021-4: the database refuses an end before the start, on the same day and across days', function (array $schedule): void {
    $event = Event::factory()->create();

    expect(fn () => DB::transaction(fn () => insertActivity($event, $schedule)))->toThrow(QueryException::class);
})->with([
    'same day, earlier time' => [['starts_at' => '12:00:00', 'ends_at' => '11:59:00']],
    'earlier day' => [['starts_on' => '2027-03-11', 'ends_on' => '2027-03-10', 'starts_at' => '08:00:00', 'ends_at' => '23:00:00']],
]);

it('CA-021-4: an activity may end on a later day at an earlier time of day', function (): void {
    $event = Event::factory()->create();

    insertActivity($event, ['starts_on' => '2027-03-10', 'ends_on' => '2027-03-11', 'starts_at' => '22:00:00', 'ends_at' => '02:00:00']);

    expect(DB::table('event_activities')->count())->toBe(1);
});

it('CA-021-4: the factory builds scheduled and unscheduled activities', function (): void {
    $scheduled = EventActivity::factory()->scheduled()->create();
    CurrentOrganization::forget();
    $unscheduled = EventActivity::factory()->unscheduled()->create();

    expect($scheduled->isScheduled())->toBeTrue()
        ->and($unscheduled->isScheduled())->toBeFalse()
        ->and($unscheduled->starts_at)->toBeNull();
});

it('CA-021-4: an activity created from a submission is unique per submission', function (): void {
    $event = Event::factory()->create();
    CurrentOrganization::forget();
    $submission = Submission::factory()->create(['event_id' => $event->id, 'organization_id' => $event->organization_id]);

    insertActivity($event, ['submission_id' => $submission->id]);

    expect(fn () => DB::transaction(fn () => insertActivity($event, ['submission_id' => $submission->id])))->toThrow(QueryException::class);
});

it('CA-021-4: the composite foreign key refuses an event or a submission of another organization', function (): void {
    $event = Event::factory()->create();
    $other = Organization::factory()->create();
    CurrentOrganization::forget();
    $submission = Submission::factory()->create(['event_id' => $event->id, 'organization_id' => $event->organization_id]);

    expect(fn () => DB::transaction(fn () => insertActivity($event, ['organization_id' => $other->id])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => insertActivity(
            Event::factory()->create(['organization_id' => $other->id]),
            ['submission_id' => $submission->id],
        )))->toThrow(QueryException::class);
});

it('CA-021-16: deleting an activity is logical and it disappears from the lists', function (): void {
    $organization = Organization::factory()->create();
    CurrentOrganization::set($organization);
    $activity = EventActivity::factory()->create();

    $activity->delete();

    expect(EventActivity::query()->count())->toBe(0)
        ->and(EventActivity::query()->withTrashed()->count())->toBe(1);
});

it('CA-021-6: an activity admits several categories and the pivot refuses a category of another event', function (): void {
    $organization = Organization::factory()->create();
    CurrentOrganization::set($organization);
    $activity = EventActivity::factory()->create();
    $first = ActivityCategory::factory()->create(['event_id' => $activity->event_id]);
    $second = ActivityCategory::factory()->create(['event_id' => $activity->event_id]);
    $foreign = ActivityCategory::factory()->create();

    foreach ([$first, $second] as $category) {
        DB::table('activity_activity_category')->insert([
            'activity_id' => $activity->id,
            'activity_category_id' => $category->id,
            'organization_id' => $organization->id,
            'event_id' => $activity->event_id,
        ]);
    }

    expect($activity->categories()->count())->toBe(2)
        ->and(fn () => DB::transaction(fn () => DB::table('activity_activity_category')->insert([
            'activity_id' => $activity->id,
            'activity_category_id' => $foreign->id,
            'organization_id' => $organization->id,
            'event_id' => $activity->event_id,
        ])))->toThrow(QueryException::class);
});

it('CA-021-6: deleting a category or an activity cleans the pivot and deletes neither the other side', function (): void {
    $organization = Organization::factory()->create();
    CurrentOrganization::set($organization);
    $activity = EventActivity::factory()->create();
    $category = ActivityCategory::factory()->create(['event_id' => $activity->event_id]);
    DB::table('activity_activity_category')->insert([
        'activity_id' => $activity->id,
        'activity_category_id' => $category->id,
        'organization_id' => $organization->id,
        'event_id' => $activity->event_id,
    ]);

    $category->delete();

    expect(DB::table('activity_activity_category')->count())->toBe(0)
        ->and(EventActivity::query()->count())->toBe(1);
});

it('CA-021-17: the organization of an activity cannot change', function (): void {
    $activity = EventActivity::factory()->create();
    $other = Organization::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('event_activities')
        ->where('id', $activity->id)->update(['organization_id' => $other->id])))->toThrow(QueryException::class);
});
