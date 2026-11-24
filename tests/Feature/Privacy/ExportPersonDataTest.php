<?php

declare(strict_types=1);

use App\Actions\Privacy\ExportPersonData;
use App\Enums\OrganizationRole;
use App\Exceptions\Domain\PersonDataNotFound;
use App\Models\Activity;
use App\Models\Organization;
use App\Models\User;
use App\Support\Privacy\PersonDataExport;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\Support\Memberships;
use Tests\Support\PersonFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PersonFixtures::prepare();
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @param  array<string, string>  $data */
function exportAs(User $actor, Organization $organization, array $data = ['email' => PersonFixtures::EMAIL, 'reason' => 'data_subject_request']): PersonDataExport
{
    return CurrentOrganization::run($organization, fn (): PersonDataExport => app(ExportPersonData::class)->handle($actor, $data));
}

/** @return array<string, mixed> */
function exportedJson(PersonDataExport $export, Organization $organization): array
{
    $path = "organizations/{$organization->id}/exports/person-{$export->id}.json";
    $disk = Storage::disk((string) config('filesystems.private_uploads_disk'));
    expect($disk->exists($path))->toBeTrue();

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $disk->get($path), true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

it('CA-014-20: exports only the data of the person to a private file with a temporary signed link, and audits it', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    // Otra persona de la misma organización: no debe aparecer.
    PersonFixtures::person($organization, 'otra.persona@example.test', withAccount: false);

    $export = exportAs($admin, $organization);
    $data = exportedJson($export, $organization);

    expect($data['subject']['email'])->toBe(PersonFixtures::EMAIL)
        ->and($data['contacts'])->toHaveCount(1)
        ->and($data['contacts'][0]['full_name'])->toBe(PersonFixtures::NAME)
        ->and($data['submissions'])->toHaveCount(1)
        ->and($data['submissions'][0]['id'])->toBe($person['submission']->id)
        ->and($data['submissions'][0]['participant'][0]['email'])->toBe(PersonFixtures::EMAIL)
        ->and($data['submissions'][0]['files'][0]['original_name'])->toBe('cv-ana-torres.pdf')
        ->and($data['submissions'][0]['status_changes'][0]['author_comment'])->toBe('Enhorabuena Ana')
        ->and($data['speakers'])->toHaveCount(1)
        ->and($data['speakers'][0]['has_photo'])->toBeTrue()
        ->and($data['account']['email'])->toBe(PersonFixtures::EMAIL)
        ->and($data['account']['two_factor_enabled'])->toBeTrue()
        ->and($data['reviews_made'][0]['answers'][0]['value_text'])->toBe('Mi opinion como evaluadora')
        ->and($data['emails_sent'])->toHaveCount(1);

    // Evaluaciones de su propuesta: sin identidad del evaluador.
    expect($data['submissions'][0]['evaluations'][0]['value_text'])->toContain('no cita fuentes');

    $json = json_encode($data, JSON_THROW_ON_ERROR);
    foreach (['otra.persona@example.test', 'luis.coautor@example.test', 'Luis Coautor', 'storage_path', 'token_hash', 'password', 'app_authentication', 'recipient_hash', 'fingerprint', 'Motivo interno', 'reviewer_id', 'organizations/'] as $forbidden) {
        expect($json)->not->toContain($forbidden);
    }

    expect($export->url)->toContain('signature=')->and($export->url)->toContain('/person-data-exports/'.$export->id)
        ->and($export->counts['submissions'])->toBe(1);

    $entry = Activity::query()->where('event', 'person_data_exported')->sole();
    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->organization_id)->toBe($organization->id)
        ->and($entry->properties['export_id'])->toBe($export->id)
        ->and($entry->properties['reason'])->toBe('data_subject_request')
        ->and(strtolower((string) json_encode($entry->properties)))->not->toContain('ana');
});

it('CA-014-20: the file is delivered only through the signed link and only to who asked for it', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    PersonFixtures::person($organization);
    $other = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $export = exportAs($admin, $organization);

    $this->get($export->url)->assertRedirect(); // sin sesión

    $response = $this->actingAs($admin)->get($export->url);
    $response->assertOk()->assertDownload("persona-{$export->id}.json");

    // Otra persona con el mismo permiso y la misma URL firmada: no sirve (el parámetro firmado es de quien la pidió).
    $this->actingAs($other)->get($export->url)->assertForbidden();

    // Firma alterada.
    $this->actingAs($admin)->get($export->url.'x')->assertForbidden();

    // La URL con el identificador de otro usuario, firmada para él, pero que no pidió la exportación: 404.
    $forged = URL::temporarySignedRoute('person-data-exports.download', now()->addMinutes(5), [
        'organization' => $organization->slug, 'export' => $export->id, 'user' => $other->id,
    ]);
    $this->actingAs($other)->get($forged)->assertNotFound();
});

it('CA-014-20: the link and the file expire after their term', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    PersonFixtures::person($organization);
    $export = exportAs($admin, $organization);

    $this->travel(ExportPersonData::URL_MINUTES + 1)->minutes();
    $this->actingAs($admin)->get($export->url)->assertForbidden();

    // Una firma nueva no la resucita: pasadas 24 horas la exportación ya no existe.
    $this->travel(25)->hours();
    $this->actingAs($admin)->get(URL::temporarySignedRoute('person-data-exports.download', now()->addMinutes(5), [
        'organization' => $organization->slug, 'export' => $export->id, 'user' => $admin->id,
    ]))->assertNotFound();
});

it('CA-014-20: the scheduled purge of exports also deletes the person exports after 24 hours', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    PersonFixtures::person($organization);
    $export = exportAs($admin, $organization);
    $disk = Storage::disk((string) config('filesystems.private_uploads_disk'));
    $path = "organizations/{$organization->id}/exports/person-{$export->id}.json";

    $this->artisan('submission-exports:purge')->assertSuccessful();
    expect($disk->exists($path))->toBeTrue();

    touch($disk->path($path), time() - 90000);
    $this->artisan('submission-exports:purge')->assertSuccessful();
    expect($disk->exists($path))->toBeFalse();
});

it('CA-014-23: exporting a person who only exists in another organization answers 404 and writes nothing', function (): void {
    [$organizationA, $adminA] = PersonFixtures::organizationWithAdmin();
    [$organizationB] = PersonFixtures::organizationWithAdmin();
    PersonFixtures::person($organizationB);

    expect(fn () => exportAs($adminA, $organizationA))->toThrow(PersonDataNotFound::class);

    expect(Activity::query()->where('event', 'person_data_exported')->count())->toBe(0)
        ->and(Storage::disk((string) config('filesystems.private_uploads_disk'))->allFiles('organizations/'.$organizationA->id))->toBe([]);
});

it('CA-014-30: without personal_data.manage the export is 403 and writes nothing', function (OrganizationRole $role): void {
    [$organization] = PersonFixtures::organizationWithAdmin();
    PersonFixtures::person($organization);
    $member = Memberships::attach(User::factory()->create(), $organization, $role);

    expect(fn () => exportAs($member, $organization))->toThrow(AuthorizationException::class);

    expect(Activity::query()->where('event', 'person_data_exported')->count())->toBe(0);
})->with([OrganizationRole::Organizer, OrganizationRole::Reviewer]);

it('CA-014-20: the export needs a valid email and a reason from the closed list', function (array $data): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    PersonFixtures::person($organization);

    expect(fn () => exportAs($admin, $organization, $data))->toThrow(ValidationException::class);
})->with([
    'no email' => [['reason' => 'data_subject_request']],
    'not an email' => [['email' => 'no-es-correo', 'reason' => 'data_subject_request']],
    'free-text reason' => [['email' => PersonFixtures::EMAIL, 'reason' => 'me lo pidio por telefono']],
]);
