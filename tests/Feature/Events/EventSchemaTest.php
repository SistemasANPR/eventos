<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Fila válida de `events` para insertar directo, sin pasar por Eloquent.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validEventRow(string $organizationId, array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::uuid7(),
        'organization_id' => $organizationId,
        'name' => json_encode(['es' => 'Congreso']),
        'slug' => 'congreso-'.Str::random(6),
        'type' => 'congress',
        'starts_at' => '2027-03-10 15:00:00',
        'ends_at' => '2027-03-12 23:00:00',
        'timezone' => 'America/Mexico_City',
        'locales' => json_encode(['es', 'en']),
        'default_locale' => 'es',
        'currency' => 'MXN',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

/**
 * Ejecuta dentro de un savepoint: PostgreSQL aborta la transacción de la prueba tras un error.
 *
 * @param  array<string, mixed>  $row
 */
function insertEvent(array $row): bool
{
    return DB::transaction(fn (): bool => DB::table('events')->insert($row));
}

it('CA-002-19: has the organization-scoped unique indexes', function (): void {
    $definitions = DB::table('pg_indexes')->where('tablename', 'events')->pluck('indexdef')->implode("\n");

    expect($definitions)->toContain('UNIQUE INDEX')
        ->toMatch('/UNIQUE INDEX \S+ ON public\.events USING btree \(organization_id, slug\)/')
        ->toMatch('/UNIQUE INDEX \S+ ON public\.events USING btree \(id, organization_id\)/');
});

it('CA-002-19: the same slug is allowed in two organizations but not twice in one', function (): void {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    insertEvent(validEventRow($organizationA->id, ['slug' => 'congreso']));
    insertEvent(validEventRow($organizationB->id, ['slug' => 'congreso']));

    expect(fn () => insertEvent(validEventRow($organizationA->id, ['slug' => 'congreso'])))
        ->toThrow(QueryException::class);
});

it('CA-002-19: the database refuses invalid values', function (string $column, mixed $value): void {
    $organization = Organization::factory()->create();

    expect(fn () => insertEvent(validEventRow($organization->id, [$column => $value])))
        ->toThrow(QueryException::class);
})->with([
    'unknown status' => ['status', 'borrador'],
    'unknown type' => ['type', 'concierto'],
    'unknown currency' => ['currency', 'EUR'],
    'ends before it starts' => ['ends_at', '2027-03-09 00:00:00'],
    'locale outside es, en, pt' => ['locales', '["es","fr"]'],
    'default locale not among the locales' => ['default_locale', 'pt'],
]);

it('CA-002-19: a composite foreign key rejects an event of another organization', function (): void {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $event = Event::factory()->create(['organization_id' => $organizationA->id]);

    Schema::create('probe_children', function ($table): void {
        $table->uuid('id')->primary();
        $table->uuid('event_id');
        $table->uuid('organization_id');
        $table->foreign(['event_id', 'organization_id'])->references(['id', 'organization_id'])->on('events');
    });

    $row = fn (string $organizationId): array => [
        'id' => (string) Str::uuid7(),
        'event_id' => $event->id,
        'organization_id' => $organizationId,
    ];

    expect(DB::transaction(fn (): bool => DB::table('probe_children')->insert($row($organizationA->id))))->toBeTrue()
        ->and(fn () => DB::transaction(fn (): bool => DB::table('probe_children')->insert($row($organizationB->id))))
        ->toThrow(QueryException::class);
});
