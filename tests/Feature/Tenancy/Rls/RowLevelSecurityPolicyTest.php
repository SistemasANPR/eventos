<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\PlatformAccess;
use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\AppDatabaseRole;

/*
 * RLS de PostgreSQL como segunda barrera de aislamiento (plan 014, H-014-2, DA-2).
 *
 * La conexión de las pruebas es de un usuario con privilegios amplios, que ignora
 * RLS; cada prueba que debe ver su efecto actúa con `AppDatabaseRole::run()`, un
 * rol sin BYPASSRLS y sin ser propietario de las tablas, como el de la aplicación
 * en staging y producción.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PlatformAccess::disable();
    CurrentOrganization::forget();
});

afterEach(function (): void {
    CurrentOrganization::forget();
    PlatformAccess::disable();
});

/** @return array{0: Organization, 1: Organization} A con 2 contactos y B con 3 */
function rlsTwoOrganizations(): array
{
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    Contact::factory()->count(2)->create(['organization_id' => $a->id]);
    Contact::factory()->count(3)->create(['organization_id' => $b->id]);

    return [$a, $b];
}

/**
 * Ejecuta `$callback` como el rol de aplicación con `$organization` en contexto.
 *
 * @param  Closure(): mixed  $callback
 */
function rlsActingAs(Organization $organization, Closure $callback): mixed
{
    return AppDatabaseRole::run(fn (): mixed => CurrentOrganization::run($organization, $callback));
}

function rlsSetting(): ?string
{
    $value = DB::selectOne("select nullif(current_setting('app.organization_id', true), '') as value")->value;

    return is_string($value) ? $value : null;
}

it('CA-014-6: returns no rows and rejects writes when no organization is set', function (): void {
    rlsTwoOrganizations();

    $visible = AppDatabaseRole::run(fn (): int => DB::table('contacts')->count());
    $owner = DB::table('contacts')->count();

    expect($visible)->toBe(0)
        ->and($owner)->toBe(5);

    $insert = fn () => AppDatabaseRole::run(fn () => DB::table('contacts')->insert([
        'id' => (string) Str::uuid(),
        'organization_id' => Organization::query()->first()->id,
        'full_name' => 'X',
        'email' => 'x@example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    expect($insert)->toThrow(QueryException::class, 'row-level security');
});

it('CA-014-7: returns only the rows of the organization in context even when the Eloquent scope is skipped', function (): void {
    [$a, $b] = rlsTwoOrganizations();

    $seenByA = AppDatabaseRole::run(fn (): array => CurrentOrganization::run($a, fn (): array => [
        'raw' => DB::table('contacts')->count(),
        'unscoped' => Contact::withoutGlobalScopes()->count(),
        'orgs' => DB::table('contacts')->pluck('organization_id')->unique()->values()->all(),
    ]));
    $seenByB = AppDatabaseRole::run(fn (): int => CurrentOrganization::run($b, fn (): int => Contact::withoutGlobalScopes()->count()));

    expect($seenByA['raw'])->toBe(2)
        ->and($seenByA['unscoped'])->toBe(2)
        ->and($seenByA['orgs'])->toBe([$a->id])
        ->and($seenByB)->toBe(3);
});

it('CA-014-8: rejects inserting or updating a row of another organization and cannot touch its rows', function (): void {
    [$a, $b] = rlsTwoOrganizations();
    $ownB = DB::table('contacts')->where('organization_id', $b->id)->value('id');
    $ownA = DB::table('contacts')->where('organization_id', $a->id)->value('id');

    $row = fn (string $organizationId): array => [
        'id' => (string) Str::uuid(),
        'organization_id' => $organizationId,
        'full_name' => 'X',
        'email' => 'x@example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    expect(fn () => rlsActingAs($a, fn () => DB::table('contacts')->insert($row($b->id))))
        ->toThrow(QueryException::class, 'row-level security')
        ->and(fn () => rlsActingAs($a, fn () => DB::table('contacts')->where('id', $ownA)->update(['organization_id' => $b->id])))
        // Mover la fila a otra organización lo rechaza ya el disparador `prevent_organization_id_change`
        // (otra defensa de la base); lo que RLS añade para el UPDATE es que ni siquiera ve las filas de B.
        ->toThrow(QueryException::class);

    // Las filas de B ni se ven ni se modifican ni se borran desde A.
    $affected = rlsActingAs($a, fn (): array => [
        DB::table('contacts')->where('id', $ownB)->update(['full_name' => 'hackeado']),
        DB::table('contacts')->where('id', $ownB)->delete(),
    ]);

    expect($affected)->toBe([0, 0])
        ->and(DB::table('contacts')->where('id', $ownB)->value('full_name'))->not->toBe('hackeado');
});

it('CA-014-9: the database context is cleared when the in-process context is forgotten', function (): void {
    [$a] = rlsTwoOrganizations();

    $inside = CurrentOrganization::run($a, fn (): ?string => rlsSetting());

    expect($inside)->toBe($a->id)
        ->and(rlsSetting())->toBeNull();

    CurrentOrganization::set($a);
    expect(rlsSetting())->toBe($a->id);
    CurrentOrganization::forget();
    expect(rlsSetting())->toBeNull();
});

it('CA-014-9: a reused or reconnected connection never inherits the previous organization', function (): void {
    $a = Organization::factory()->create();
    $read = fn (Connection $connection): ?string => $connection->selectOne("select nullif(current_setting('app.organization_id', true), '') as value")->value;

    // Una conexión aparte (con su propia sesión, como la de otro trabajador): la de la prueba va en una transacción.
    $other = AppDatabaseRole::separateConnection();

    CurrentOrganization::set($a);
    expect($read($other))->toBe($a->id);

    // El proceso olvida el contexto: la conexión reutilizada se ve sin organización en su siguiente consulta.
    CurrentOrganization::forget();
    expect($read($other))->toBeNull();

    // Una conexión cerrada y reabierta recibe el contexto vigente antes de su primera consulta.
    CurrentOrganization::set($a);
    $other->disconnect();
    expect($read($other))->toBe($a->id);

    CurrentOrganization::forget();
    $other->disconnect();
    expect($read($other))->toBeNull();

    $other->disconnect();
});

it('CA-014-9: a rolled back transaction does not leave a stale value on the connection', function (): void {
    [$a] = rlsTwoOrganizations();

    CurrentOrganization::set($a);
    expect(rlsSetting())->toBe($a->id);

    // Dentro del punto de guardado el contexto se limpia; PostgreSQL revierte ese SET al deshacerlo
    // y el valor anterior (A) reaparece en la sesión aunque el proceso ya no tiene contexto.
    DB::beginTransaction();
    CurrentOrganization::forget();
    expect(rlsSetting())->toBeNull();
    DB::rollBack();

    expect(rlsSetting())->toBeNull();
});

it('CA-014-10: the application role has no BYPASSRLS and is not a table owner; tables are not forced', function (): void {
    AppDatabaseRole::provision();

    $role = DB::selectOne('select rolsuper, rolbypassrls, rolcanlogin from pg_roles where rolname = ?', [AppDatabaseRole::NAME]);
    $tables = RowLevelSecurity::protectedTables();

    expect($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse()
        ->and($tables)->not->toBeEmpty();

    foreach ($tables as $table) {
        $meta = DB::selectOne(
            'select c.relrowsecurity, c.relforcerowsecurity, pg_get_userbyid(c.relowner) as owner from pg_class c where c.oid = ?::regclass',
            [$table],
        );

        expect($meta->relrowsecurity)->toBeTrue("{$table} sin RLS")
            ->and($meta->relforcerowsecurity)->toBeFalse("{$table} no debe forzar RLS: el rol de migraciones (propietario) no depende de ella")
            ->and($meta->owner)->not->toBe(AppDatabaseRole::NAME);
    }
});

it('CA-014-10: the migrations role works without RLS and without a context', function (): void {
    rlsTwoOrganizations();

    // La conexión de las pruebas hace el papel del rol de migraciones: propietario, sin FORCE.
    expect(DB::table('contacts')->count())->toBe(5);

    Schema::create('rls_probe_migration', function ($table): void {
        $table->uuid('organization_id');
    });
    RowLevelSecurity::enable('rls_probe_migration');
    DB::table('rls_probe_migration')->insert(['organization_id' => (string) Str::uuid()]);

    expect(DB::table('rls_probe_migration')->count())->toBe(1);
});

it('CA-014-7: a platform read through withoutOrganizationScope sees every organization but cannot write across them', function (): void {
    [$a, $b] = rlsTwoOrganizations();
    $platform = User::factory()->platformAdmin()->create();
    $this->actingAs($platform);

    $total = AppDatabaseRole::run(fn (): int => Contact::withoutOrganizationScope()->count());

    expect($total)->toBe(5);

    // El acceso de plataforma es solo de lectura: no concede escritura sobre filas ajenas.
    $affected = AppDatabaseRole::run(fn (): int => DB::table('contacts')->where('organization_id', $b->id)->update(['full_name' => 'x']));
    expect($affected)->toBe(0);
});

it('CA-014-7: without the audited bypass the platform flag is off and nothing leaks', function (): void {
    rlsTwoOrganizations();

    expect(PlatformAccess::isEnabled())->toBeFalse()
        ->and(AppDatabaseRole::run(fn (): int => DB::table('contacts')->count()))->toBe(0);
});
