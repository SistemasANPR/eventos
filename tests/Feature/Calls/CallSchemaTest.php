<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SubmissionTypes;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function callRow(Event $event, array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::uuid7(),
        'organization_id' => $event->organization_id,
        'event_id' => $event->id,
        'slug' => 'sesiones-'.Str::random(6),
        'submission_type_id' => SubmissionTypes::for($event)->id,
        'title' => json_encode(['es' => 'Convocatoria']),
        'locales' => json_encode(['es']),
        'opens_at' => '2027-01-10 15:00:00',
        'closes_at' => '2027-02-15 23:00:00',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

/** @param  array<string, mixed>  $row */
function insertCall(array $row): bool
{
    return DB::transaction(fn (): bool => DB::table('call_for_submissions')->insert($row));
}

it('CA-004-8: the composite foreign key refuses a call whose organization is not the one of its event', function (): void {
    $event = Event::factory()->create();
    $other = Organization::factory()->create();

    expect(fn () => insertCall(callRow($event, ['organization_id' => $other->id])))->toThrow(QueryException::class);
});

it('CA-004-8: a consistent organization and event are accepted', function (): void {
    $event = Event::factory()->create();

    expect(insertCall(callRow($event)))->toBeTrue();
});

it('CA-004-4: the slug is unique per event, not per organization', function (): void {
    $event = Event::factory()->create();
    $sibling = Event::factory()->create(['organization_id' => $event->organization_id]);
    insertCall(callRow($event, ['slug' => 'sesiones']));

    expect(insertCall(callRow($sibling, ['slug' => 'sesiones'])))->toBeTrue()
        ->and(fn () => insertCall(callRow($event, ['slug' => 'sesiones'])))->toThrow(QueryException::class);
});

it('CA-004-2: the database refuses a close that is not after the opening', function (string $closes): void {
    $event = Event::factory()->create();

    expect(fn () => insertCall(callRow($event, ['closes_at' => $closes])))->toThrow(QueryException::class);
})->with(['equal' => '2027-01-10 15:00:00', 'earlier' => '2027-01-01 00:00:00']);

it('CA-004-2: the database refuses results before the close and accepts them empty', function (): void {
    $event = Event::factory()->create();

    expect(fn () => insertCall(callRow($event, ['results_at' => '2027-02-01 00:00:00'])))->toThrow(QueryException::class)
        ->and(insertCall(callRow($event, ['results_at' => null])))->toBeTrue()
        ->and(insertCall(callRow($event, ['results_at' => '2027-03-01 00:00:00'])))->toBeTrue();
});

it('CA-004-4: the database refuses invalid values', function (string $column, mixed $value): void {
    $event = Event::factory()->create();

    expect(fn () => insertCall(callRow($event, [$column => $value])))->toThrow(QueryException::class);
})->with([
    'unknown status' => ['status', 'abierta'],
    'language outside es, en and pt' => ['locales', '["es","fr"]'],
]);

it('CA-004-8: the id and organization pair is unique, ready for composite keys of later plans', function (): void {
    $definitions = DB::table('pg_indexes')->where('tablename', 'call_for_submissions')->pluck('indexdef')->implode("\n");

    expect($definitions)->toMatch('/UNIQUE INDEX \S+ ON public\.call_for_submissions USING btree \(id, organization_id\)/');
});
