<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Guardiana de RLS (plan 014, CA-014-11). Toda tabla con `organization_id` tiene RLS
 * y la política `tenant_isolation`, o está en la lista cerrada de tablas de plataforma
 * (`RowLevelSecurity::PLATFORM_TABLES`) con su justificación. Una tabla nueva sin
 * ninguna de las dos hace fallar esta prueba: quien la crea añade una línea a su
 * migración (`RowLevelSecurity::enable('tabla')`) o declara la excepción aquí.
 */

uses(RefreshDatabase::class);

it('CA-014-11: every table with organization_id has RLS and its policy or is in the closed list', function (): void {
    $unprotected = RowLevelSecurity::unprotectedTables();

    expect($unprotected)->toBe([], 'Tablas con organization_id sin RLS ni excepción declarada: '.implode(', ', $unprotected)
        .'. Llama a RowLevelSecurity::enable() en su migración o añádela a RowLevelSecurity::PLATFORM_TABLES con su justificación.');
});

it('CA-014-11: a new table with organization_id and no policy fails the guardian until it is protected', function (): void {
    Schema::create('rls_guardian_probe', function ($table): void {
        $table->uuid('organization_id');
    });

    expect(RowLevelSecurity::unprotectedTables())->toBe(['rls_guardian_probe']);

    RowLevelSecurity::enable('rls_guardian_probe');

    expect(RowLevelSecurity::unprotectedTables())->toBe([])
        ->and(RowLevelSecurity::protectedTables())->toContain('rls_guardian_probe');

    RowLevelSecurity::disable('rls_guardian_probe');

    expect(RowLevelSecurity::unprotectedTables())->toBe(['rls_guardian_probe']);
});

it('CA-014-11: enabling twice is idempotent and each protected table has exactly its policies', function (): void {
    Schema::create('rls_guardian_probe', function ($table): void {
        $table->uuid('organization_id');
    });

    RowLevelSecurity::enable('rls_guardian_probe');
    RowLevelSecurity::enable('rls_guardian_probe');

    $policies = DB::table('pg_policies')->where('tablename', 'rls_guardian_probe')->orderBy('policyname')->pluck('policyname')->all();

    expect($policies)->toBe([RowLevelSecurity::PLATFORM_READ_POLICY, RowLevelSecurity::POLICY]);
});

it('CA-014-11: the closed list has no stale entries and every entry is justified', function (): void {
    foreach (RowLevelSecurity::PLATFORM_TABLES as $table => $reason) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} figura en la lista cerrada pero ya no existe")
            ->and(Schema::hasColumn($table, 'organization_id'))->toBeTrue("{$table} figura en la lista cerrada pero no tiene organization_id: sobra")
            ->and(mb_strlen($reason))->toBeGreaterThan(20, "{$table} necesita una justificación");
    }
});

it('CA-014-11: no table in the closed list also has the policy', function (): void {
    $withPolicy = RowLevelSecurity::protectedTables();

    expect(array_intersect($withPolicy, array_keys(RowLevelSecurity::PLATFORM_TABLES)))->toBe([]);
});

it('CA-014-11: the status report lists the tables and says whether RLS has real effect for the connected role', function (): void {
    $status = RowLevelSecurity::status();

    expect($status['unprotected_tables'])->toBe([])
        ->and($status['protected_tables'])->not->toBeEmpty()
        // Las pruebas se conectan con un superusuario: RLS está definido pero no tiene efecto con ese rol.
        ->and($status['role_bypasses_rls'])->toBeTrue()
        ->and($status['effective'])->toBeFalse();
});
