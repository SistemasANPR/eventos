<?php

declare(strict_types=1);

use App\Actions\Organizations\CreatePlatformUser;
use App\Models\Activity;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

it('CA-016-29: a fact audited inside an organization context is saved with that organization', function (): void {
    $organization = Organization::factory()->create();

    CurrentOrganization::run($organization, fn () => activity()->event('probe')->log('probe'));

    expect(Activity::query()->where('event', 'probe')->sole()->organization_id)->toBe($organization->id);
});

it('CA-016-29: the organization in the properties is used when there is no context, and wins over the context', function (): void {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();

    activity()->event('from_properties')->withProperties(['organization_id' => $organization->id])->log('x');
    CurrentOrganization::run($other, fn () => activity()->event('explicit')->withProperties(['organization_id' => $organization->id])->log('x'));

    expect(Activity::query()->where('event', 'from_properties')->sole()->organization_id)->toBe($organization->id)
        ->and(Activity::query()->where('event', 'explicit')->sole()->organization_id)->toBe($organization->id);
});

it('CA-016-29: a platform fact has no organization', function (): void {
    Notification::fake();

    app(CreatePlatformUser::class)->handle('soporte@example.test', 'Soporte');

    expect(Activity::query()->where('event', 'platform_user_created')->sole()->organization_id)->toBeNull();
});

it('CA-016-29: a property that is not an organization id is ignored instead of breaking the audit write', function (): void {
    activity()->event('garbage')->withProperties(['organization_id' => 'not-a-uuid'])->log('x');

    expect(Activity::query()->where('event', 'garbage')->sole()->organization_id)->toBeNull();
});

it('CA-016-29: the migration fills existing records only when the value is an existing organization, and down() keeps the properties', function (): void {
    $organization = Organization::factory()->create();
    $migration = require database_path('migrations/2026_11_10_650000_add_organization_id_to_activity_log.php');

    $migration->down();
    expect(Schema::hasColumn('activity_log', 'organization_id'))->toBeFalse();

    $row = fn (string $event, mixed $organizationId): array => [
        'log_name' => 'default', 'description' => $event, 'event' => $event,
        'properties' => json_encode(['organization_id' => $organizationId]),
        'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('activity_log')->insert([
        $row('known', $organization->id),
        $row('unknown_org', (string) Str::uuid()),
        $row('garbage', 'not-a-uuid'),
        $row('number', 42),
        ['log_name' => 'default', 'description' => 'none', 'event' => 'none', 'properties' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $migration->up();

    $byEvent = DB::table('activity_log')->pluck('organization_id', 'event');
    expect($byEvent['known'])->toBe($organization->id)
        ->and($byEvent['unknown_org'])->toBeNull()
        ->and($byEvent['garbage'])->toBeNull()
        ->and($byEvent['number'])->toBeNull()
        ->and($byEvent['none'])->toBeNull();
});

it('CA-016-29: the database refuses an organization that does not exist', function (): void {
    expect(fn () => DB::transaction(fn () => DB::table('activity_log')->insert([
        'log_name' => 'default', 'description' => 'x', 'organization_id' => (string) Str::uuid(),
        'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});
