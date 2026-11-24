<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\PlatformAccess;
use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AppDatabaseRole;
use Tests\Support\Memberships;
use Tests\Support\ProbeRlsJob;

/*
 * Contrato de contexto por tipo de ejecución bajo RLS (plan 014, DA-2): cola, tareas
 * programadas, inicio de sesión y consola, con el rol de aplicación (sin BYPASSRLS).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PlatformAccess::disable();
    CurrentOrganization::forget();
    ProbeRlsJob::reset();
});

afterEach(function (): void {
    CurrentOrganization::forget();
    PlatformAccess::disable();
});

it('CA-014-12: a queued job with an organization in its payload sees and changes only that organization', function (): void {
    config(['queue.default' => 'database']);
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    Contact::factory()->count(2)->create(['organization_id' => $a->id]);
    Contact::factory()->count(3)->create(['organization_id' => $b->id]);

    ProbeRlsJob::dispatch($a->id);

    // El trabajador corre con el rol de aplicación: lo que el job ve y toca lo decide la base.
    AppDatabaseRole::run(fn () => Artisan::call('queue:work', ['connection' => 'database', '--once' => true]));

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(ProbeRlsJob::$seen)->toBe(['visible' => 2, 'touched' => 2])
        ->and(DB::table('contacts')->where('organization_id', $a->id)->where('full_name', 'tocado')->count())->toBe(2)
        ->and(DB::table('contacts')->where('organization_id', $b->id)->where('full_name', 'tocado')->count())->toBe(0);
});

it('CA-014-12: a job without context sees no rows and modifies nothing', function (): void {
    $a = Organization::factory()->create();
    Contact::factory()->count(2)->create(['organization_id' => $a->id]);

    [$visible, $touched] = AppDatabaseRole::run(fn (): array => [
        DB::table('contacts')->count(),
        DB::table('contacts')->update(['full_name' => 'tocado']),
    ]);

    expect($visible)->toBe(0)
        ->and($touched)->toBe(0)
        ->and(DB::table('contacts')->where('full_name', 'tocado')->count())->toBe(0);
});

it('CA-014-12: the worker does not carry the organization of one job to the next', function (): void {
    config(['queue.default' => 'database']);
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    Contact::factory()->count(2)->create(['organization_id' => $a->id]);
    Contact::factory()->count(3)->create(['organization_id' => $b->id]);

    ProbeRlsJob::dispatch($a->id);
    ProbeRlsJob::dispatch($b->id);

    AppDatabaseRole::run(function (): void {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
    });

    expect(ProbeRlsJob::$history)->toBe([['visible' => 2, 'touched' => 2], ['visible' => 3, 'touched' => 3]])
        ->and(DB::selectOne("select nullif(current_setting('app.organization_id', true), '') as v")->v)->toBeNull();
});

it('CA-014-13: the scheduled purge processes each organization inside its own context under RLS', function (): void {
    Storage::fake(config('filesystems.private_uploads_disk'));
    $this->travelTo('2027-02-10 12:00:00');

    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    $files = [];
    foreach ([$a, $b] as $organization) {
        $files[$organization->id] = CurrentOrganization::run($organization, function () use ($organization): SubmissionFile {
            $file = SubmissionFile::factory()->create(['organization_id' => $organization->id, 'uploaded_at' => now()->subHours(30)]);
            Storage::disk(config('filesystems.private_uploads_disk'))->put($file->storage_path, "%PDF-1.4\n");

            return $file;
        });
    }

    $exit = AppDatabaseRole::run(fn (): int => Artisan::call('files:purge-orphans'));

    expect($exit)->toBe(0);
    foreach ($files as $file) {
        expect(DB::table('submission_files')->where('id', $file->id)->whereNotNull('deleted_at')->exists())->toBeTrue('el archivo de '.$file->organization_id.' no se procesó');
    }
});

it('CA-014-15: sign-in, membership and organization switching work under RLS because platform tables are outside it', function (): void {
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(['email' => 'persona@example.com']), $organization, OrganizationRole::OrgAdmin);

    $result = AppDatabaseRole::run(function (): array {
        // Sin organización en contexto, como en el inicio de sesión: usuario, membresía, roles y permisos.
        $found = User::query()->where('email', 'persona@example.com')->first();

        return [
            'user' => $found?->id,
            'memberships' => $found?->organizations()->count(),
            'roles' => DB::table('model_has_roles')->where('model_id', $found?->id)->count(),
            'permissions' => DB::table('permissions')->count(),
            'reset_tokens' => DB::table('password_reset_tokens')->count(),
        ];
    });

    expect($result['user'])->toBe($user->id)
        ->and($result['memberships'])->toBe(1)
        ->and($result['roles'])->toBe(1)
        ->and($result['permissions'])->toBeGreaterThan(0);

    // Y el cambio de organización: con la organización destino en contexto el rol se resuelve igual.
    $hasRole = AppDatabaseRole::run(fn (): bool => CurrentOrganization::run($organization, fn (): bool => User::query()->findOrFail($user->id)->hasRole(OrganizationRole::OrgAdmin->value)));
    expect($hasRole)->toBeTrue();
});

it('CA-014-15: the status report says RLS is effective for a role that cannot bypass it', function (): void {
    $status = AppDatabaseRole::run(fn (): array => RowLevelSecurity::status());

    expect($status['role_bypasses_rls'])->toBeFalse()
        ->and($status['effective'])->toBeTrue()
        ->and($status['unprotected_tables'])->toBe([]);
});

it('CA-014-15: the organization-creation command works from the console with the application role', function (): void {
    // Consola: no hay contexto al empezar; la Action abre el de la organización nueva con `run()`.
    $exit = AppDatabaseRole::run(fn (): int => Artisan::call('app:create-organization', [
        'slug' => 'nueva-org',
        'name' => 'Nueva Org',
        '--admin-email' => 'admin@example.com',
        '--admin-name' => 'Admin',
    ]));

    expect($exit)->toBe(0)
        ->and(DB::table('organizations')->where('slug', 'nueva-org')->exists())->toBeTrue()
        ->and(CurrentOrganization::has())->toBeFalse();
});
