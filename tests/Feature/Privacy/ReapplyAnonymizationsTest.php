<?php

declare(strict_types=1);

use App\Actions\Privacy\AnonymizePerson;
use App\Actions\Privacy\ImportAnonymizationLog;
use App\Enums\OrganizationRole;
use App\Models\AnonymizationLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\Privacy\PersonIdentity;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\PersonFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PersonFixtures::prepare();
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

const RESTORED_TABLES = ['contacts', 'submission_participants', 'speakers', 'users', 'mail_dispatches', 'submissions', 'review_answers', 'review_history', 'activity_log'];

/**
 * Lo que había en la base ANTES de anonimizar, para simular un respaldo anterior.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function backupOf(): array
{
    return collect(RESTORED_TABLES)->mapWithKeys(fn (string $table): array => [
        $table => DB::table($table)->get()->map(fn (object $row): array => (array) $row)->all(),
    ])->all();
}

/**
 * Deja la base como estaba en el respaldo (el registro de anonimizaciones no se restaura: ver el procedimiento).
 *
 * @param  array<string, list<array<string, mixed>>>  $backup
 */
function restore(array $backup): void
{
    foreach ($backup as $table => $rows) {
        foreach ($rows as $row) {
            DB::table($table)->where('id', $row['id'])->update(array_diff_key($row, ['id' => 1, 'organization_id' => 1]));
        }
    }
}

function anonymizeBy(User $admin, Organization $organization, string $email = PersonFixtures::EMAIL): void
{
    CurrentOrganization::run($organization, fn () => app(AnonymizePerson::class)->handle($admin, PersonFixtures::anonymizeData($email)));
}

it('CA-014-19: after restoring an older backup, the command anonymizes again the people of the log and reports the total', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    [$organizationB] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    $personB = PersonFixtures::person($organizationB, withAccount: false);
    $backup = backupOf();
    anonymizeBy($admin, $organization);

    $file = tempnam(sys_get_temp_dir(), 'anon');
    $this->artisan('app:export-anonymization-log', ['path' => $file])->assertSuccessful();
    // El respaldo es anterior: trae los datos y NO trae la huella.
    restore($backup);
    DB::table('anonymization_log')->delete();
    expect(DB::table('contacts')->where('id', $person['contact']->id)->value('full_name'))->toBe(PersonFixtures::NAME);

    $this->artisan('app:reapply-anonymizations', ['--from-file' => $file])
        ->expectsOutputToContain('Registro cargado: 1 huella(s) nueva(s)')
        ->expectsOutputToContain('Personas reaplicadas: 1 de 1 con datos')
        ->assertSuccessful();
    unlink($file);

    expect(DB::table('contacts')->where('id', $person['contact']->id)->value('full_name'))->toBe('Persona anonimizada')
        ->and(DB::table('submission_participants')->where('id', $person['participant']->id)->value('email'))->toBe("anonymized-{$person['participant']->id}@anonymized.invalid")
        ->and(DB::table('speakers')->where('id', $person['speaker']->id)->value('first_name'))->toBe('Persona')
        ->and(DB::table('users')->where('id', $person['user']->id)->value('email'))->toBe("anonymized-{$person['user']->id}@anonymized.invalid")
        ->and(DB::table('mail_dispatches')->where('id', $person['dispatch']->id)->value('recipient_hash'))->toBe(hash('sha256', 'anonymized:'.$person['dispatch']->id))
        ->and(DB::table('review_answers')->where('id', $person['answer']->id)->value('value_text'))->toBe('[anonimizado]')
        ->and((string) DB::table('submissions')->where('id', $person['submission']->id)->value('answers'))->toContain('[anonimizado]')
        ->and(strtolower((string) DB::table('activity_log')->where('organization_id', $organization->id)->get()->toJson()))->not->toContain('ana.torres@example.test');

    // La coautora y la otra organización, con el mismo correo, no se tocan: la huella es de cada organización.
    expect(DB::table('submission_participants')->where('id', $person['coParticipant']->id)->value('anonymized_at'))->toBeNull()
        ->and(DB::table('contacts')->where('id', $personB['contact']->id)->value('full_name'))->toBe(PersonFixtures::NAME)
        ->and(DB::table('anonymization_log')->where('organization_id', $organizationB->id)->count())->toBe(0);

    $entry = DB::table('activity_log')->where('event', 'anonymizations_reapplied')->sole();
    expect(json_decode($entry->properties, true)['reapplied'])->toBe(1)
        ->and(strtolower($entry->properties))->not->toContain('ana');
});

it('CA-014-19: running the command again is idempotent: it finds nobody and changes nothing', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    PersonFixtures::person($organization);
    $backup = backupOf();
    anonymizeBy($admin, $organization);
    restore($backup);

    $this->artisan('app:reapply-anonymizations')->expectsOutputToContain('Personas reaplicadas: 1 de 1')->assertSuccessful();
    $snapshot = collect(RESTORED_TABLES)->map(fn (string $table): string => DB::table($table)->get()->toJson())->all();

    $this->artisan('app:reapply-anonymizations')->expectsOutputToContain('Personas reaplicadas: 0 de 0')->assertSuccessful();

    expect(collect(RESTORED_TABLES)->map(fn (string $table): string => DB::table($table)->get()->toJson())->all())->toBe($snapshot);
});

it('CA-014-19: the dry run only counts and changes nothing', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    $backup = backupOf();
    anonymizeBy($admin, $organization);
    restore($backup);

    $this->artisan('app:reapply-anonymizations', ['--dry-run' => true])
        ->expectsOutputToContain('Simulacion: se reaplicarian 1 de 1')
        ->assertSuccessful();

    expect(DB::table('contacts')->where('id', $person['contact']->id)->value('full_name'))->toBe(PersonFixtures::NAME)
        ->and(DB::table('activity_log')->where('event', 'anonymizations_reapplied')->count())->toBe(0);
});

it('CA-014-19: the dry run cannot be combined with loading a file, because loading changes the database', function (): void {
    $this->artisan('app:reapply-anonymizations', ['--dry-run' => true, '--from-file' => '/tmp/no-importa.json'])->assertFailed();
});

it('CA-014-19: without anybody in the log nothing is anonymized', function (): void {
    [$organization] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);

    $this->artisan('app:reapply-anonymizations')->expectsOutputToContain('Personas reaplicadas: 0 de 0')->assertSuccessful();

    expect(DB::table('contacts')->where('id', $person['contact']->id)->value('full_name'))->toBe(PersonFixtures::NAME);
});

it('CA-014-19: the last active administrator is not forced: it is reported and the command ends with an error', function (): void {
    [$organization] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    CurrentOrganization::run($organization, function () use ($organization, $person): void {
        foreach (User::query()->activeMembersOf($organization)->role(OrganizationRole::OrgAdmin->value)->get() as $existing) {
            $organization->users()->updateExistingPivot($existing->id, ['status' => 'deactivated', 'deactivated_at' => now()]);
        }
        $person['user']->assignRole(OrganizationRole::OrgAdmin->value);
        AnonymizationLog::factory()->create(['email_hash' => PersonIdentity::organizationHash(PersonFixtures::EMAIL, $organization->id)]);
    });

    $this->artisan('app:reapply-anonymizations')
        ->expectsOutputToContain('Omitidas por last_administrator: 1')
        ->assertFailed();

    expect(DB::table('contacts')->where('id', $person['contact']->id)->value('full_name'))->toBe(PersonFixtures::NAME);
});

it('CA-014-19: a single organization can be chosen by id or slug, and an unknown one is an error', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    $backup = backupOf();
    anonymizeBy($admin, $organization);
    restore($backup);

    $this->artisan('app:reapply-anonymizations', ['--organization' => 'no-existe'])->expectsOutputToContain('No existe esa organizacion.')->assertFailed();
    $this->artisan('app:reapply-anonymizations', ['--organization' => $organization->slug])->expectsOutputToContain('Personas reaplicadas: 1 de 1')->assertSuccessful();

    expect(DB::table('contacts')->where('id', $person['contact']->id)->value('full_name'))->toBe('Persona anonimizada');
});

it('CA-014-19: a malformed log file is rejected as a whole and loads nothing', function (string $content): void {
    $file = tempnam(sys_get_temp_dir(), 'anon');
    file_put_contents($file, $content);

    $this->artisan('app:reapply-anonymizations', ['--from-file' => $file])->assertFailed();
    unlink($file);

    expect(DB::table('anonymization_log')->count())->toBe(0);
})->with([
    'not json' => ['esto no es json'],
    'unknown version' => ['{"version":2,"entries":[]}'],
    'bad hash' => ['{"version":1,"entries":[{"organization_id":"01a20f08-cc54-7ef9-b95a-b5e63d9af217","email_hash":"corto","anonymized_at":"2026-11-24T10:00:00Z"}]}'],
]);

it('CA-014-19: loading the log adds only what is missing, skips organizations that no longer exist and is idempotent', function (): void {
    [$organization] = PersonFixtures::organizationWithAdmin();
    $hash = PersonIdentity::organizationHash('alguien@example.test', $organization->id);
    $payload = ['version' => 1, 'entries' => [
        ['organization_id' => $organization->id, 'email_hash' => $hash, 'anonymized_at' => '2026-11-20T10:00:00Z'],
        ['organization_id' => '01a20f08-cc54-7ef9-b95a-b5e63d9af217', 'email_hash' => str_repeat('a', 64), 'anonymized_at' => '2026-11-20T10:00:00Z'],
    ]];

    expect(app(ImportAnonymizationLog::class)->handle($payload))->toBe(['added' => 1, 'existing' => 0, 'skipped' => 1])
        ->and(app(ImportAnonymizationLog::class)->handle($payload))->toBe(['added' => 0, 'existing' => 1, 'skipped' => 1])
        ->and(DB::table('anonymization_log')->count())->toBe(1);

    expect(fn () => app(ImportAnonymizationLog::class)->handle(['version' => 1]))->toThrow(ValidationException::class);
});

it('CA-014-19: the exported file has only fingerprints, dates and organizations, never an email', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    PersonFixtures::person($organization);
    anonymizeBy($admin, $organization);
    $file = tempnam(sys_get_temp_dir(), 'anon');

    $this->artisan('app:export-anonymization-log', ['path' => $file])->assertSuccessful();
    $content = (string) file_get_contents($file);
    unlink($file);

    $data = json_decode($content, true);
    expect($data['version'])->toBe(1)
        ->and($data['entries'])->toHaveCount(1)
        ->and($data['entries'][0]['email_hash'])->toBe(PersonIdentity::organizationHash(PersonFixtures::EMAIL, $organization->id))
        ->and(strtolower($content))->not->toContain('ana')
        ->and($content)->not->toContain('@');
});

it('CA-014-19: the export refuses a destination inside public/ or in a folder that does not exist', function (): void {
    $this->artisan('app:export-anonymization-log', ['path' => public_path('registro.json')])->assertFailed();
    $this->artisan('app:export-anonymization-log', ['path' => '/no/existe/registro.json'])->assertFailed();

    expect(file_exists(public_path('registro.json')))->toBeFalse();
});
