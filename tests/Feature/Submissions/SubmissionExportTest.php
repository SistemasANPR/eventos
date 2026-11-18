<?php

declare(strict_types=1);

use App\Actions\SubmissionExports\ExportSubmissions;
use App\Enums\OrganizationRole;
use App\Enums\SubmissionStatus;
use App\Exceptions\Domain\SubmissionExportTooLarge;
use App\Jobs\ExportSubmissionsJob;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Models\User;
use App\Support\SubmissionExport\FormulaGuard;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

beforeEach(function (): void {
    Storage::fake(config('filesystems.private_uploads_disk'));
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Propuesta de la convocatoria con su autor principal. Reutiliza convocatoria, tipo, formulario y aviso de
 * `$base` si se da (la factory crea uno nuevo de cada uno y el aviso es único por evento y versión).
 *
 * @param  array<string, mixed>  $overrides
 */
function exportSubmission(Organization $organization, Event $event, array $overrides = [], ?Submission $base = null): Submission
{
    $shared = $base === null ? [] : [
        'call_for_submissions_id' => $base->call_for_submissions_id,
        'submission_type_id' => $base->submission_type_id,
        'form_definition_id' => $base->form_definition_id,
        'track_id' => $base->track_id,
        'privacy_notice_version_id' => $base->privacy_notice_version_id,
    ];

    $submission = Submission::factory()->create([
        'organization_id' => $organization->id,
        'event_id' => $event->id,
        'form_snapshot' => ['fields' => [
            ['key' => 'f_resumen', 'type' => 'long_text', 'label' => ['es' => 'Resumen'], 'config' => []],
            ['key' => 'f_nivel', 'type' => 'single_choice', 'label' => ['es' => 'Nivel'], 'config' => ['options' => [
                ['value' => 'o_basico', 'label' => ['es' => 'Básico']],
            ]]],
            ['key' => 'f_archivo', 'type' => 'file', 'label' => ['es' => 'Presentación'], 'config' => []],
        ]],
        'answers' => ['f_resumen' => 'Texto normal', 'f_nivel' => 'o_basico', 'f_archivo' => ['x1', 'x2']],
        ...$shared,
        ...$overrides,
    ]);

    SubmissionParticipant::factory()->create([
        'organization_id' => $organization->id,
        'submission_id' => $submission->id,
        'full_name' => 'Ana Autora',
        'email' => 'ana@example.test',
    ]);

    return $submission;
}

/**
 * Corre la exportación completa (la cola es `sync` en pruebas) y devuelve la tabla exportada como filas.
 *
 * @param  array<string, mixed>  $filters
 * @return list<list<string>> la primera fila son los encabezados
 */
function runExport(User $user, Event $event, array $filters = [], string $format = 'csv'): array
{
    $exportId = app(ExportSubmissions::class)->handle($event, $filters, $user, $format);

    return readExport($event->organization_id, $exportId, $format);
}

/** @return list<list<string>> */
function readExport(string $organizationId, string $exportId, string $format): array
{
    $path = "organizations/{$organizationId}/exports/{$exportId}.{$format}";
    $disk = Storage::disk(config('filesystems.private_uploads_disk'));
    expect($disk->exists($path))->toBeTrue();

    if ($format === 'xlsx') {
        $sheet = IOFactory::load($disk->path($path))->getActiveSheet();

        return array_map(
            static fn (array $row): array => array_map(static fn ($cell): string => (string) $cell, $row),
            $sheet->toArray(null, false, false, false),
        );
    }

    $handle = fopen('php://memory', 'r+');
    fwrite($handle, (string) $disk->get($path));
    rewind($handle);
    $rows = [];

    while (($row = fgetcsv($handle, escape: '\\')) !== false) {
        $rows[] = array_map(static fn ($cell): string => (string) $cell, $row);
    }

    fclose($handle);

    return $rows;
}

/**
 * @param  list<list<string>>  $rows  la primera fila son los encabezados
 * @return list<string> los valores de una columna, sin el encabezado
 */
function exportColumn(array $rows, string $heading): array
{
    $index = array_search($heading, $rows[0], true);
    expect($index)->not->toBeFalse();

    return array_map(static fn (array $row): string => $row[$index], array_slice($rows, 1));
}

it('CA-009-11: the export holds only the submissions of the filter and of the organization, with fixed and form columns', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    [$otherOrganization, $otherEvent] = formScenario();

    $first = exportSubmission($organization, $event, ['title' => 'Propuesta incluida']);
    exportSubmission($organization, $event, ['title' => 'Propuesta fuera del filtro', 'status' => SubmissionStatus::Withdrawn, 'withdrawn_at' => now()], $first);
    exportSubmission($otherOrganization, $otherEvent, ['title' => 'Propuesta ajena']);

    Auth::login($user);
    CurrentOrganization::set($organization);
    $rows = runExport($user, $event, ['status' => 'submitted']);

    expect($rows[0])->toContain('Título', 'Estado', 'Resumen', 'Nivel', 'Presentación', 'Autor', 'Correo')
        ->and(exportColumn($rows, 'Título'))->toBe(['Propuesta incluida'])
        ->and(exportColumn($rows, 'Nivel'))->toBe(['Básico'])
        ->and(exportColumn($rows, 'Autor'))->toBe(['Ana Autora'])
        ->and(exportColumn($rows, 'Correo'))->toBe(['ana@example.test']);
});

it('CA-009-11: the export is queued and the xlsx format works too', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    exportSubmission($organization, $event, ['title' => 'Propuesta xlsx']);
    Auth::login($user);
    CurrentOrganization::set($organization);

    Queue::fake();
    $exportId = app(ExportSubmissions::class)->handle($event, [], $user, 'xlsx');
    Queue::assertPushed(ExportSubmissionsJob::class, 1);
    Queue::assertPushed(ExportSubmissionsJob::class, fn (ExportSubmissionsJob $job): bool => $job->exportId === $exportId);

    // Se ejecuta el job encolado para leer el archivo.
    $job = Queue::pushed(ExportSubmissionsJob::class)->first();
    app()->call([$job, 'handle']);

    expect(exportColumn(readExport($organization->id, $exportId, 'xlsx'), 'Título'))->toBe(['Propuesta xlsx']);
});

it('CA-009-12: cells that start with a formula character are prefixed so they are not executed', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    $payloads = [
        '=HYPERLINK("http://malo.example","clic")',
        "\t=cmd|' /C calc'!A0",
        '+1+1',
        '-2+3',
        '@SUM(1+1)',
        "\r=1+1",
    ];
    $base = null;

    foreach ($payloads as $payload) {
        $base = exportSubmission($organization, $event, ['title' => $payload, 'answers' => ['f_resumen' => $payload, 'f_nivel' => 'o_basico', 'f_archivo' => []]], $base);
    }

    Auth::login($user);
    CurrentOrganization::set($organization);

    foreach (['csv', 'xlsx'] as $format) {
        $rows = runExport($user, $event, [], $format);

        foreach (array_merge(exportColumn($rows, 'Título'), exportColumn($rows, 'Resumen')) as $cell) {
            expect($cell)->toStartWith("'")
                ->and(in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true))->toBeFalse();
        }
    }
});

it('CA-009-12: FormulaGuard prefixes only dangerous values', function (): void {
    expect(FormulaGuard::neutralize('=1+1'))->toBe("'=1+1")
        ->and(FormulaGuard::neutralize('+1'))->toBe("'+1")
        ->and(FormulaGuard::neutralize('-1'))->toBe("'-1")
        ->and(FormulaGuard::neutralize('@a'))->toBe("'@a")
        ->and(FormulaGuard::neutralize("\tx"))->toBe("'\tx")
        ->and(FormulaGuard::neutralize("\rx"))->toBe("'\rx")
        ->and(FormulaGuard::neutralize('Texto normal'))->toBe('Texto normal')
        ->and(FormulaGuard::neutralize('a=b'))->toBe('a=b')
        ->and(FormulaGuard::neutralize(''))->toBe('');
});

it('CA-009-13: the export holds no tokens, passwords or file URLs', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    $submission = exportSubmission($organization, $event);
    Auth::login($user);
    CurrentOrganization::set($organization);

    $rows = runExport($user, $event);
    $everything = strtolower(json_encode($rows, JSON_UNESCAPED_UNICODE));

    expect($rows[0])->not->toContain('idempotency_key', 'payload_hash', 'token_hash')
        ->and($everything)->not->toContain('token')
        ->and($everything)->not->toContain('password')
        ->and($everything)->not->toContain('http')
        ->and($everything)->not->toContain('storage')
        ->and($everything)->not->toContain(strtolower((string) $submission->idempotency_key))
        // Los archivos salen solo como cantidad.
        ->and(exportColumn($rows, 'Presentación'))->toBe(['2']);
});

it('CA-009-14: the export is audited with who, when, filters and number of rows, and no content', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    exportSubmission($organization, $event, ['title' => 'Titulo secreto']);
    Auth::login($user);
    CurrentOrganization::set($organization);

    $exportId = app(ExportSubmissions::class)->handle($event, ['status' => 'submitted'], $user, 'csv');

    $entry = Activity::query()->where('event', 'submissions_exported')->sole();

    expect($entry->causer_id)->toBe($user->id)
        ->and($entry->created_at)->not->toBeNull()
        ->and($entry->properties['export_id'])->toBe($exportId)
        ->and($entry->properties['rows'])->toBe(1)
        ->and($entry->properties['format'])->toBe('csv')
        ->and($entry->properties['filters']['status'])->toBe('submitted')
        ->and(json_encode($entry->properties))->not->toContain('Titulo secreto')
        ->and(json_encode($entry->properties))->not->toContain('ana@example.test');
});

it('CA-009-15: without submissions.export nothing is queued and no file is created', function (): void {
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);
    exportSubmission($organization, $event);
    Auth::login($reviewer);
    CurrentOrganization::set($organization);
    Queue::fake();

    expect(fn () => app(ExportSubmissions::class)->handle($event, [], $reviewer, 'csv'))
        ->toThrow(AuthorizationException::class);

    Queue::assertNothingPushed();
    expect(Storage::disk(config('filesystems.private_uploads_disk'))->allFiles())->toBe([])
        ->and(Activity::query()->where('event', 'like', 'submissions_export%')->count())->toBe(0);
});

it('CA-009-15: a member with submissions.view but without submissions.export cannot export', function (): void {
    [$organization, $event] = formScenario(OrganizationRole::Organizer);
    $viewer = Memberships::attach(User::factory()->create(), $organization);
    CurrentOrganization::run($organization, fn () => $viewer->givePermissionTo(['events.manage', 'submissions.view']));
    Auth::login($viewer);
    CurrentOrganization::set($organization);
    Queue::fake();

    expect(fn () => app(ExportSubmissions::class)->handle($event, [], $viewer, 'csv'))
        ->toThrow(AuthorizationException::class);

    Queue::assertNothingPushed();
});

it('CA-009-15: an event of another organization is not found', function (): void {
    [$organization, , $user] = formScenario(OrganizationRole::Organizer);
    [, $otherEvent] = formScenario();
    Auth::login($user);
    CurrentOrganization::set($organization);
    Queue::fake();

    expect(fn () => app(ExportSubmissions::class)->handle($otherEvent, [], $user, 'csv'))
        ->toThrow(ModelNotFoundException::class);

    Queue::assertNothingPushed();
});

it('CA-009-16: more rows than the limit are rejected asking for narrower filters, and nothing is queued', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    $base = exportSubmission($organization, $event);
    exportSubmission($organization, $event, [], $base);
    exportSubmission($organization, $event, [], $base);
    Auth::login($user);
    CurrentOrganization::set($organization);
    Queue::fake();

    expect(ExportSubmissions::MAX_ROWS)->toBe(5000);

    // Con tope 2 y 3 filas filtradas se rechaza; con tope 3 pasa.
    $action = new ExportSubmissions(maxRows: 2);
    expect(fn () => $action->handle($event, [], $user, 'csv'))->toThrow(SubmissionExportTooLarge::class);
    Queue::assertNothingPushed();
    expect(Storage::disk(config('filesystems.private_uploads_disk'))->allFiles())->toBe([]);

    (new ExportSubmissions(maxRows: 3))->handle($event, [], $user, 'csv');
    Queue::assertPushed(ExportSubmissionsJob::class, 1);
});

it('CA-009-17: submitted and withdrawn submissions both appear with their status', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    $base = exportSubmission($organization, $event, ['title' => 'Enviada']);
    exportSubmission($organization, $event, ['title' => 'Retirada', 'status' => SubmissionStatus::Withdrawn, 'withdrawn_at' => now()], $base);
    Auth::login($user);
    CurrentOrganization::set($organization);

    $rows = runExport($user, $event);
    $byTitle = array_combine(exportColumn($rows, 'Título'), exportColumn($rows, 'Estado'));

    expect($byTitle)->toBe(['Enviada' => 'submitted', 'Retirada' => 'withdrawn']);
});

it('CA-009-18: the job sets the organization context itself and holds no data of another organization', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    [$otherOrganization, $otherEvent] = formScenario();
    exportSubmission($organization, $event, ['title' => 'Propia']);
    exportSubmission($otherOrganization, $otherEvent, ['title' => 'Ajena']);
    Auth::login($user);
    CurrentOrganization::set($organization);
    Queue::fake();

    $exportId = app(ExportSubmissions::class)->handle($event, [], $user, 'csv');
    $job = Queue::pushed(ExportSubmissionsJob::class)->first();

    // Sin sesión y sin contexto, como en un worker.
    Auth::logout();
    CurrentOrganization::forget();
    app()->call([$job, 'handle']);

    CurrentOrganization::forget();
    $rows = readExport($organization->id, $exportId, 'csv');

    expect(exportColumn($rows, 'Título'))->toBe(['Propia'])
        ->and(CurrentOrganization::has())->toBeFalse();
});

it('CA-009-18: the job is idempotent: running it twice leaves one audit entry', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    exportSubmission($organization, $event);
    Auth::login($user);
    CurrentOrganization::set($organization);
    Queue::fake();

    app(ExportSubmissions::class)->handle($event, [], $user, 'csv');
    $job = Queue::pushed(ExportSubmissionsJob::class)->first();
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    expect(Activity::query()->where('event', 'submissions_exported')->count())->toBe(1);
});

it('CA-009-18: the job fails visibly if the user lost the permission before it ran', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    exportSubmission($organization, $event);
    Auth::login($user);
    CurrentOrganization::set($organization);
    Queue::fake();

    app(ExportSubmissions::class)->handle($event, [], $user, 'csv');
    $job = Queue::pushed(ExportSubmissionsJob::class)->first();

    CurrentOrganization::run($organization, fn () => $user->syncRoles([]));
    CurrentOrganization::forget();

    expect(fn () => app()->call([$job, 'handle']))->toThrow(AuthorizationException::class);
    expect(Activity::query()->where('event', 'submissions_exported')->count())->toBe(0);
});

it('CA-009-19: the download is a 5-minute signed URL only for who asked for it', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    $other = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    exportSubmission($organization, $event);
    Auth::login($user);
    CurrentOrganization::set($organization);
    $exportId = app(ExportSubmissions::class)->handle($event, [], $user, 'csv');
    CurrentOrganization::forget();

    $url = URL::temporarySignedRoute('submission-exports.download', now()->addMinutes(5), [
        'organization' => $organization->slug, 'export' => $exportId, 'user' => $user->id,
    ]);

    // Quien la pidió descarga el archivo como adjunto, con nombre generado.
    $response = $this->actingAs($user)->get($url)->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('attachment')
        ->and($response->headers->get('content-disposition'))->toContain($exportId);

    // Otro miembro de la organización, con permiso, tampoco puede usarla.
    $this->actingAs($other)->get($url)->assertForbidden();

    // Una URL manipulada o sin firma no sirve.
    $this->actingAs($user)->get(preg_replace('/signature=[^&]+/', 'signature=falsa', $url))->assertForbidden();
    $this->actingAs($user)->get(route('submission-exports.download', ['organization' => $organization->slug, 'export' => $exportId, 'user' => $user->id]))->assertForbidden();

    // A los 5 minutos caduca.
    $this->travel(6)->minutes();
    $this->actingAs($user)->get($url)->assertForbidden();
});

it('CA-009-19: the scheduled task deletes export files after 24 hours and keeps the recent ones', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    exportSubmission($organization, $event);
    Auth::login($user);
    CurrentOrganization::set($organization);
    $old = app(ExportSubmissions::class)->handle($event, [], $user, 'csv');
    $this->travel(23)->hours();
    $recent = app(ExportSubmissions::class)->handle($event, [], $user, 'csv');
    $disk = Storage::disk(config('filesystems.private_uploads_disk'));
    $oldPath = "organizations/{$organization->id}/exports/{$old}.csv";
    $recentPath = "organizations/{$organization->id}/exports/{$recent}.csv";

    // Pasadas 25 horas desde la primera: la vieja se borra y la reciente (2 h) se conserva. La fecha de
    // modificación del archivo es la del sistema y `travel()` no la mueve: se fija a la del reloj simulado.
    $this->travel(2)->hours();
    touch($disk->path($recentPath), now()->subHours(2)->getTimestamp());
    Artisan::call('submission-exports:purge');

    expect($disk->exists($oldPath))->toBeFalse()
        ->and($disk->exists($recentPath))->toBeTrue();
});

it('CA-009-19: the scheduled task also deletes an old export file that was never audited, and keeps a fresh one', function (): void {
    [$organization] = formScenario(OrganizationRole::Organizer);
    $disk = Storage::disk(config('filesystems.private_uploads_disk'));
    $stale = "organizations/{$organization->id}/exports/".Str::uuid().'.xlsx';
    $fresh = "organizations/{$organization->id}/exports/".Str::uuid().'.xlsx';
    // Un archivo de otra carpeta no se toca aunque sea viejo.
    $other = "organizations/{$organization->id}/submissions/".Str::uuid().'.pdf';

    foreach ([$stale, $fresh, $other] as $path) {
        $disk->put($path, 'contenido');
    }

    touch($disk->path($stale), now()->subHours(25)->getTimestamp());
    touch($disk->path($other), now()->subHours(25)->getTimestamp());
    Artisan::call('submission-exports:purge');

    expect($disk->exists($stale))->toBeFalse()
        ->and($disk->exists($fresh))->toBeTrue()
        ->and($disk->exists($other))->toBeTrue();
});

it('CA-009-19: an expired export cannot be downloaded even with a valid signature', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    exportSubmission($organization, $event);
    Auth::login($user);
    CurrentOrganization::set($organization);
    $exportId = app(ExportSubmissions::class)->handle($event, [], $user, 'csv');
    CurrentOrganization::forget();
    Storage::disk(config('filesystems.private_uploads_disk'))->delete("organizations/{$organization->id}/exports/{$exportId}.csv");

    $url = URL::temporarySignedRoute('submission-exports.download', now()->addMinutes(5), [
        'organization' => $organization->slug, 'export' => $exportId, 'user' => $user->id,
    ]);

    $this->actingAs($user)->get($url)->assertNotFound();
});

it('CA-009-2: an invalid filter value is rejected with a validation error and nothing is queued', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    exportSubmission($organization, $event);
    Auth::login($user);
    CurrentOrganization::set($organization);
    Queue::fake();

    foreach ([['track_id' => (string) Str::uuid()], ['status' => 'inventado'], ['from' => '2027-02-01', 'until' => '2027-01-01'], ['from' => 'no-es-fecha']] as $filters) {
        expect(fn () => app(ExportSubmissions::class)->handle($event, $filters, $user, 'csv'))
            ->toThrow(ValidationException::class);
    }

    Queue::assertNothingPushed();
});
