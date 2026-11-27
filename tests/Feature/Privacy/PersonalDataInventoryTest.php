<?php

declare(strict_types=1);

use App\Actions\Privacy\AnonymizePerson;
use App\Models\Activity;
use App\Models\ActivityContact;
use App\Models\Contact;
use App\Models\KnownDevice;
use App\Models\MailDispatch;
use App\Models\ReviewAnswer;
use App\Models\ReviewRevision;
use App\Models\Speaker;
use App\Models\Submission;
use App\Models\SubmissionAccessToken;
use App\Models\SubmissionFile;
use App\Models\SubmissionParticipant;
use App\Models\SubmissionStatusChange;
use App\Models\User;
use App\Support\Privacy\PersonalDataInventory;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PersonFixtures;
use Tests\Support\TenantModels;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/*
 * Guardiana del inventario de datos personales (plan 014, CA-014-26). Un modelo nuevo debe declararse cubierto
 * por la anonimización o exento con su razón; una columna nueva de una tabla cubierta debe clasificarse; y toda
 * columna declarada `replaced` debe cambiar de verdad al anonimizar. Una prueba que se debilita en silencio
 * deja datos personales sin cubrir: si falla, se corrige el código o el inventario, no la prueba.
 */

it('CA-014-26: every model of app/Models is either covered by the anonymization or exempt with a reason', function (string $model): void {
    $covered = array_key_exists($model, PersonalDataInventory::COVERED);
    $exempt = array_key_exists($model, PersonalDataInventory::EXEMPT);

    expect($covered || $exempt)->toBeTrue("{$model} no figura en PersonalDataInventory: declararlo cubierto (y cubrirlo en PersonDataEraser) o exento con su razón.")
        ->and($covered && $exempt)->toBeFalse("{$model} figura a la vez como cubierto y exento.");

    if ($exempt) {
        expect(trim(PersonalDataInventory::EXEMPT[$model]))->not->toBe('');
    }
})->with(fn (): array => TenantModels::dataset(TenantModels::all()));

it('CA-014-26: the inventory lists no model that no longer exists', function (): void {
    foreach ([...array_keys(PersonalDataInventory::COVERED), ...array_keys(PersonalDataInventory::EXEMPT)] as $model) {
        expect(class_exists($model))->toBeTrue("{$model} figura en el inventario pero ya no existe.");
        expect(in_array($model, TenantModels::all(), true))->toBeTrue("{$model} figura en el inventario pero no es un modelo de app/Models.");
    }
});

it('CA-014-26: every column of a covered table is classified, so a new column without declaring fails', function (string $model): void {
    /** @var Model $instance */
    $instance = new $model;
    $actual = Schema::getColumnListing($instance->getTable());
    $declared = PersonalDataInventory::allColumns($model);

    expect(array_values(array_diff($actual, $declared)))->toBe([], "{$model}: columnas sin clasificar en PersonalDataInventory (¿datos personales nuevos?).")
        ->and(array_values(array_diff($declared, $actual)))->toBe([], "{$model}: columnas declaradas que no existen en la tabla.");
})->with(fn (): array => TenantModels::dataset(array_keys(PersonalDataInventory::COVERED)));

it('CA-014-26: every column declared as replaced changes when the person is anonymized', function (): void {
    PersonFixtures::prepare();
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);

    /** @var array<class-string<Model>, Model> $witness una fila de cada modelo con columnas `replaced` */
    $witness = [
        SubmissionParticipant::class => $person['participant'],
        Speaker::class => $person['speaker'],
        Submission::class => $person['submission'],
        SubmissionStatusChange::class => $person['change'],
        SubmissionFile::class => $person['file'],
        SubmissionAccessToken::class => $person['token'],
        MailDispatch::class => $person['dispatch'],
        ReviewAnswer::class => $person['answer'],
        ReviewRevision::class => $person['revision'],
        User::class => $person['user'],
        Contact::class => $person['contact'],
    ];

    $withReplaced = array_keys(array_filter(PersonalDataInventory::COVERED, fn (array $entry): bool => ($entry['replaced'] ?? []) !== []));
    expect(array_values(array_diff($withReplaced, array_keys($witness))))->toBe([], 'Un modelo con columnas `replaced` necesita un testigo en esta prueba.');

    $before = [];
    foreach ($witness as $model => $row) {
        $before[$model] = (array) DB::table((new $model)->getTable())->where('id', $row->getKey())->first();
    }

    CurrentOrganization::run($organization, fn () => app(AnonymizePerson::class)->handle($admin, PersonFixtures::anonymizeData()));

    foreach ($witness as $model => $row) {
        $after = (array) DB::table((new $model)->getTable())->where('id', $row->getKey())->first();

        foreach (PersonalDataInventory::COVERED[$model]['replaced'] ?? [] as $column) {
            expect($after[$column])->not->toBe($before[$model][$column], "{$model}.{$column} está declarada `replaced` y no cambió al anonimizar.");
        }
    }

    // Lo que se borra se borra, y la auditoría conserva el hecho sin el dato.
    expect(KnownDevice::query()->where('user_id', $person['user']->id)->exists())->toBeFalse()
        ->and(PersonalDataInventory::COVERED[KnownDevice::class]['deleted'])->toBeTrue();
    expect(strtolower((string) Activity::query()->get()->toJson()))->not->toContain('ana.torres@example.test');
});

it('CA-035-22: the activity contacts are declared as deleted in the inventory with every column classified', function (): void {
    $entry = PersonalDataInventory::COVERED[ActivityContact::class] ?? null;

    expect($entry)->not->toBeNull()
        ->and($entry['deleted'] ?? false)->toBeTrue()
        ->and(PersonalDataInventory::allColumns(ActivityContact::class))->toEqualCanonicalizing(Schema::getColumnListing((new ActivityContact)->getTable()));
});
