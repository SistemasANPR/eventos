<?php

declare(strict_types=1);

use App\Models\MailDispatch;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Tabla `mail_dispatches` (plan 008, propuesta de datos): la base misma impide los
 * duplicados y los estados inventados; no se confía solo en la aplicación.
 */

uses(RefreshDatabase::class);

/** @param  array<string, mixed>  $overrides */
function insertDispatch(string $organizationId, array $overrides = []): void
{
    DB::table('mail_dispatches')->insert([
        'id' => (string) Str::uuid(),
        'organization_id' => $organizationId,
        'notification_key' => 'submission.received',
        'idempotency_key' => 'submission.received:fact-1',
        'recipient_hash' => str_repeat('a', 64),
        'status' => 'queued',
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);
}

it('rejects a second dispatch with the same idempotency key in the same organization', function () {
    $organization = Organization::factory()->create();
    insertDispatch($organization->id);

    expect(fn () => insertDispatch($organization->id))->toThrow(QueryException::class);
});

it('allows the same idempotency key in two different organizations', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    insertDispatch($organizationA->id);
    insertDispatch($organizationB->id);

    expect(DB::table('mail_dispatches')->count())->toBe(2);
});

it('rejects a status outside queued, sent, failed and throttled', function () {
    $organization = Organization::factory()->create();

    expect(fn () => insertDispatch($organization->id, ['status' => 'borrado']))->toThrow(QueryException::class);
});

it('rejects a dispatch without organization', function () {
    expect(fn () => insertDispatch((string) Str::uuid()))->toThrow(QueryException::class);
});

it('cannot change the organization of an existing dispatch', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    insertDispatch($organizationA->id);

    expect(fn () => DB::table('mail_dispatches')->update(['organization_id' => $organizationB->id]))
        ->toThrow(QueryException::class);
});

it('has a factory that accepts an explicit organization', function () {
    $organization = Organization::factory()->create();

    $dispatch = MailDispatch::factory()->create(['organization_id' => $organization->id]);

    expect($dispatch->organization_id)->toBe($organization->id);
});
