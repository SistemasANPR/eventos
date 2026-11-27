<?php

declare(strict_types=1);

use App\Actions\Privacy\AnonymizePerson;
use App\Actions\Privacy\ExportPersonData;
use App\Actions\Privacy\ReapplyAnonymizations;
use App\Exceptions\Domain\PersonDataNotFound;
use App\Models\Activity;
use App\Models\ActivityContact;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\User;
use App\Support\Privacy\AnonymizationResult;
use App\Support\Privacy\PersonDataExport;
use App\Support\Privacy\PersonRecords;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PersonFixtures;

uses(RefreshDatabase::class);

/*
 * Plan 035, F3 (parte 3): los responsables de una actividad (`activity_contacts`) son datos personales. Se localizan por
 * correo (como contactos, participantes y ponentes), se ELIMINAN al anonimizar a la persona, entran en su exportación y
 * se vuelven a eliminar si un respaldo los trae de vuelta (CA-035-32 y 34).
 */

beforeEach(function (): void {
    PersonFixtures::prepare();
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Una actividad de un evento nuevo de la organización.
 *
 * @return array{0: Event, 1: EventActivity}
 */
function acpActivity(Organization $organization, string $title = 'Taller en el parque'): array
{
    return CurrentOrganization::run($organization, function () use ($organization, $title): array {
        $event = Event::factory()->create(['organization_id' => $organization->id]);

        return [$event, EventActivity::factory()->scheduled()->create(['event_id' => $event->id, 'title' => $title])];
    });
}

/** @param  array<string, mixed>  $attributes */
function acpContact(EventActivity $activity, array $attributes = []): ActivityContact
{
    return CurrentOrganization::run($activity->organization, fn (): ActivityContact => ActivityContact::factory()->create([
        'organization_id' => $activity->organization_id,
        'event_id' => $activity->event_id,
        'activity_id' => $activity->id,
        'name' => PersonFixtures::NAME,
        'phone' => '5512345678',
        'email' => PersonFixtures::EMAIL,
        'position' => 1,
        'is_public' => true,
        ...$attributes,
    ]));
}

function acpAnonymize(User $admin, Organization $organization, string $email = PersonFixtures::EMAIL): AnonymizationResult
{
    return CurrentOrganization::run($organization, fn (): AnonymizationResult => app(AnonymizePerson::class)->handle($admin, PersonFixtures::anonymizeData($email)));
}

function acpExport(User $admin, Organization $organization, string $email = PersonFixtures::EMAIL): PersonDataExport
{
    return CurrentOrganization::run($organization, fn (): PersonDataExport => app(ExportPersonData::class)->handle($admin, ['email' => $email, 'reason' => 'data_subject_request']));
}

/** @return array<string, mixed> */
function acpExported(PersonDataExport $export, Organization $organization): array
{
    $disk = Storage::disk((string) config('filesystems.private_uploads_disk'));

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $disk->get("organizations/{$organization->id}/exports/person-{$export->id}.json"), true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

it('CA-035-32: anonymizing a person deletes the activity contacts with her email, counts them and keeps the others', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    [, $first] = acpActivity($organization, 'Primera actividad');
    [, $second] = acpActivity($organization, 'Segunda actividad');
    acpContact($first);
    acpContact($first, ['position' => 2, 'name' => 'Luis Otro Responsable', 'email' => 'luis.otro@example.test']);
    acpContact($second, ['email' => strtoupper(PersonFixtures::EMAIL)]);
    acpContact($second, ['position' => 2, 'name' => 'Sin correo', 'email' => null, 'phone' => '5500000000']);

    $result = acpAnonymize($admin, $organization);

    $remaining = DB::table('activity_contacts')->orderBy('position')->get();

    expect($result->counts['activity_contacts'])->toBe(2)
        ->and($remaining)->toHaveCount(2)
        ->and($remaining->pluck('name')->sort()->values()->all())->toBe(['Luis Otro Responsable', 'Sin correo']);
});

it('CA-035-32: a person who is only a responsible of an activity can be anonymized', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    [, $activity] = acpActivity($organization);
    acpContact($activity, ['email' => 'solo.responsable@example.test', 'name' => 'Rosa Solo Responsable']);

    $records = CurrentOrganization::run($organization, fn (): PersonRecords => PersonRecords::find('solo.responsable@example.test'));
    $result = acpAnonymize($admin, $organization, 'solo.responsable@example.test');

    expect($records->isEmpty())->toBeFalse()
        ->and($result->counts['activity_contacts'])->toBe(1)
        ->and(DB::table('activity_contacts')->count())->toBe(0);
});

it('CA-035-32: the anonymization only deletes the responsibles of the organization in context', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    [$other] = PersonFixtures::organizationWithAdmin();
    [, $mine] = acpActivity($organization);
    [, $theirs] = acpActivity($other);
    acpContact($mine);
    $foreign = acpContact($theirs);

    $result = acpAnonymize($admin, $organization);

    expect($result->counts['activity_contacts'])->toBe(1)
        ->and(DB::table('activity_contacts')->pluck('id')->all())->toBe([$foreign->id])
        ->and(DB::table('activity_contacts')->where('id', $foreign->id)->value('email'))->toBe(PersonFixtures::EMAIL);
});

it('CA-035-32: repeating the anonymization changes nothing more and reports it was already done', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    [, $activity] = acpActivity($organization);
    acpContact($activity);

    acpAnonymize($admin, $organization);
    $again = acpAnonymize($admin, $organization);

    expect($again->isAlreadyAnonymized())->toBeTrue()
        ->and(DB::table('activity_contacts')->count())->toBe(0);
});

it('CA-035-32: neither the audit nor the anonymization log keep the name, the email or the phone of the responsible', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    [, $activity] = acpActivity($organization);
    acpContact($activity, ['phone' => '5599887766']);

    acpAnonymize($admin, $organization);

    $stored = strtolower((string) Activity::query()->get()->toJson().DB::table('anonymization_log')->get()->toJson());

    expect($stored)->not->toContain('ana.torres@example.test')
        ->and($stored)->not->toContain('ana torres')
        ->and($stored)->not->toContain('5599887766');
});

it('CA-035-34: the export includes the activity contacts of the person and only those of the organization', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    [$other] = PersonFixtures::organizationWithAdmin();
    [$event, $activity] = acpActivity($organization, 'Taller en el parque');
    [, $foreignActivity] = acpActivity($other, 'Actividad ajena');
    $mine = acpContact($activity, ['is_public' => false]);
    acpContact($activity, ['position' => 2, 'name' => 'Luis Otro Responsable', 'email' => 'luis.otro@example.test']);
    acpContact($foreignActivity);

    $export = acpExport($admin, $organization);
    $data = acpExported($export, $organization);

    expect($data['activity_contacts'])->toHaveCount(1)
        ->and($data['activity_contacts'][0])->toMatchArray([
            'name' => PersonFixtures::NAME,
            'phone' => '5512345678',
            'email' => PersonFixtures::EMAIL,
            'is_public' => false,
            'activity_id' => $activity->id,
            'activity_title' => 'Taller en el parque',
            'event_id' => $event->id,
        ])
        ->and($export->counts['activity_contacts'])->toBe(1)
        ->and(json_encode($data))->not->toContain('Luis Otro Responsable')
        ->and(json_encode($data))->not->toContain('Actividad ajena')
        ->and($mine->id)->not->toBeEmpty();
});

it('CA-035-34: a person who is only a responsible of an activity can be exported, and an unknown email is still not found', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    [, $activity] = acpActivity($organization);
    acpContact($activity, ['email' => 'solo.responsable@example.test', 'name' => 'Rosa Solo Responsable']);

    $data = acpExported(acpExport($admin, $organization, 'solo.responsable@example.test'), $organization);

    expect($data['activity_contacts'])->toHaveCount(1)
        ->and($data['contacts'])->toBe([])
        ->and(fn () => acpExport($admin, $organization, 'nadie@example.test'))->toThrow(PersonDataNotFound::class);
});

it('CA-035-32: after restoring a backup that brings the responsible back, the reapplication deletes it again', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    [, $activity] = acpActivity($organization);
    $contact = acpContact($activity);
    $backup = (array) DB::table('activity_contacts')->where('id', $contact->id)->first();

    acpAnonymize($admin, $organization);
    expect(DB::table('activity_contacts')->count())->toBe(0);

    // El respaldo es anterior: trae la fila y la huella de la persona sigue en el registro de anonimizaciones.
    DB::table('activity_contacts')->insert($backup);

    $simulated = app(ReapplyAnonymizations::class)->handle($organization, simulate: true);
    expect($simulated->reapplied)->toBe(1)->and(DB::table('activity_contacts')->count())->toBe(1);

    $report = app(ReapplyAnonymizations::class)->handle($organization);

    expect($report->found)->toBe(1)
        ->and($report->reapplied)->toBe(1)
        ->and(DB::table('activity_contacts')->count())->toBe(0);
});

it('CA-035-32: the reapplication ignores the responsibles of another person', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    [, $activity] = acpActivity($organization);
    $contact = acpContact($activity);
    $bystander = acpContact($activity, ['position' => 2, 'name' => 'Luis Otro Responsable', 'email' => 'luis.otro@example.test']);
    $backup = (array) DB::table('activity_contacts')->where('id', $contact->id)->first();

    acpAnonymize($admin, $organization);
    DB::table('activity_contacts')->insert($backup);

    app(ReapplyAnonymizations::class)->handle($organization);

    expect(DB::table('activity_contacts')->pluck('id')->all())->toBe([$bystander->id]);
});
