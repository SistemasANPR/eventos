<?php

declare(strict_types=1);

use App\Models\ActivityCategory;
use App\Models\Event;
use App\Models\Organization;
use App\Models\SpeakerCategory;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

dataset('category models', [
    'speaker categories' => [SpeakerCategory::class, 'speaker_categories'],
    'activity categories' => [ActivityCategory::class, 'activity_categories'],
]);

it('CA-021-6: the database refuses a repeated name in the same event without regard to case', function (string $model, string $table): void {
    $event = Event::factory()->create();
    $model::factory()->create(['event_id' => $event->id, 'organization_id' => $event->organization_id, 'name' => 'Académico']);

    expect(fn () => DB::transaction(fn () => $model::factory()->create([
        'event_id' => $event->id, 'organization_id' => $event->organization_id, 'name' => 'ACADÉMICO',
    ])))->toThrow(QueryException::class);
})->with('category models');

it('CA-021-6: the same name is allowed in another event, and the two catalogs are independent', function (string $model, string $table): void {
    $first = Event::factory()->create();
    $second = Event::factory()->create(['organization_id' => $first->organization_id]);

    $model::factory()->create(['event_id' => $first->id, 'organization_id' => $first->organization_id, 'name' => 'Invitado']);
    $model::factory()->create(['event_id' => $second->id, 'organization_id' => $first->organization_id, 'name' => 'Invitado']);

    expect(DB::table($table)->count())->toBe(2)
        ->and(DB::table($table === 'speaker_categories' ? 'activity_categories' : 'speaker_categories')->count())->toBe(0);
})->with('category models');

it('CA-021-6: the composite foreign key refuses an event of another organization', function (string $model, string $table): void {
    $event = Event::factory()->create();
    $other = Organization::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table($table)->insert([
        'id' => (string) Str::uuid(),
        'organization_id' => $other->id,
        'event_id' => $event->id,
        'name' => 'Cruzada',
        'position' => 1,
    ])))->toThrow(QueryException::class);
})->with('category models');
