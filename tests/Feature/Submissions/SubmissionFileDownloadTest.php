<?php

declare(strict_types=1);

use App\Actions\Submissions\DownloadSubmissionFile;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Support\Files\SubmissionFileData;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake(config('filesystems.private_uploads_disk'));
    $this->travelTo('2027-01-20 12:00:00');
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Sustituye la firma del disco por una que registra lo que se le pide: el bucket
 * real (S3) no existe en pruebas, y lo que importa es QUÉ se firma y con qué cabecera.
 *
 * @return ArrayObject<int, array{path: string, expires_at: int, disposition: mixed}>
 */
function dlRecordSignedUrls(): ArrayObject
{
    /** @var ArrayObject<int, array{path: string, expires_at: int, disposition: mixed}> $calls */
    $calls = new ArrayObject;

    Storage::disk(config('filesystems.private_uploads_disk'))->buildTemporaryUrlsUsing(
        function (string $path, DateTimeInterface $expiration, array $options) use ($calls): string {
            $calls[] = ['path' => $path, 'expires_at' => $expiration->getTimestamp(), 'disposition' => $options['ResponseContentDisposition'] ?? null];

            return 'https://bucket.test/'.$path.'?signature=abc';
        },
    );

    return $calls;
}

/** Crea un archivo de la organización, con su contenido en el disco; `attached` por defecto. */
function dlFile(Organization $organization, bool $attached = true, string $originalName = 'presentacion.pdf'): SubmissionFile
{
    return CurrentOrganization::run($organization, function () use ($organization, $attached, $originalName): SubmissionFile {
        $factory = SubmissionFile::factory();

        if ($attached) {
            $factory = $factory->attachedTo((string) Str::uuid());
        }

        $file = $factory->create(['organization_id' => $organization->id, 'original_name' => $originalName]);
        Storage::disk(config('filesystems.private_uploads_disk'))->put($file->storage_path, "%PDF-1.4\n");

        return $file;
    });
}

function dlUser(Organization $organization, ?OrganizationRole $role): User
{
    return Memberships::attach(User::factory()->create(), $organization, $role);
}

/** @return TestResponse<Response> */
function dlGet(mixed $test, ?User $user, Organization $organization, string $fileId): TestResponse
{
    $request = $user === null ? $test : $test->actingAs($user);

    return $request->get("/admin/{$organization->slug}/submission-files/{$fileId}");
}

it('CA-007-17: an organizer with files.download is redirected to a temporary signed URL of 5 minutes', function (OrganizationRole $role): void {
    $organization = Memberships::organization();
    $file = dlFile($organization);
    $user = dlUser($organization, $role);
    $signed = dlRecordSignedUrls();

    $response = dlGet($this, $user, $organization, $file->id);

    $response->assertRedirect("https://bucket.test/{$file->storage_path}?signature=abc");
    expect($signed)->toHaveCount(1)
        ->and($signed[0]['path'])->toBe($file->storage_path)
        ->and($signed[0]['expires_at'])->toBe(now()->addMinutes(5)->getTimestamp())
        ->and($signed[0]['disposition'])->toStartWith('attachment;');
})->with([
    'org_admin' => OrganizationRole::OrgAdmin,
    'organizer' => OrganizationRole::Organizer,
]);

it('CA-007-18: a file of another organization answers 404, not 403', function (): void {
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    $fileOfB = dlFile($organizationB);
    $organizerOfA = dlUser($organizationA, OrganizationRole::Organizer);
    dlRecordSignedUrls();

    dlGet($this, $organizerOfA, $organizationA, $fileOfB->id)->assertNotFound();
    // Aunque el organizador de A intente la ruta de B, no es miembro: la Policy lo niega.
    dlGet($this, $organizerOfA, $organizationB, $fileOfB->id)->assertForbidden();
});

it('CA-007-19: a member without files.download gets 403 and the attempt is audited', function (): void {
    $organization = Memberships::organization();
    $file = dlFile($organization, originalName: 'secreto-del-autor.pdf');
    $reviewer = dlUser($organization, OrganizationRole::Reviewer);
    $signed = dlRecordSignedUrls();

    dlGet($this, $reviewer, $organization, $file->id)->assertForbidden();

    $entry = Activity::query()->where('event', 'submission_file_download_denied')->sole();
    expect($signed)->toHaveCount(0)
        ->and($entry->causer_id)->toBe($reviewer->id)
        ->and($entry->subject_id)->toBe($file->id)
        ->and($entry->properties->get('organization_id'))->toBe($organization->id)
        ->and(str_contains((string) json_encode($entry->toArray()), 'secreto-del-autor'))->toBeFalse();
});

it('CA-007-19: a user that is not a member of the organization gets 403 and the attempt is audited', function (): void {
    $organization = Memberships::organization();
    $file = dlFile($organization);
    $outsider = User::factory()->create();
    dlRecordSignedUrls();

    dlGet($this, $outsider, $organization, $file->id)->assertForbidden();

    expect(Activity::query()->where('event', 'submission_file_download_denied')->count())->toBe(1);
});

it('CA-007-19: a guest is redirected to the login', function (): void {
    $organization = Memberships::organization();
    $file = dlFile($organization);

    dlGet($this, null, $organization, $file->id)->assertRedirect('/login');

    expect(Activity::query()->count())->toBe(0);
});

it('CA-007-20: an allowed download is audited with who, which file and when, without content or name', function (): void {
    $organization = Memberships::organization();
    $file = dlFile($organization, originalName: 'confidencial.pdf');
    $organizer = dlUser($organization, OrganizationRole::Organizer);
    dlRecordSignedUrls();

    dlGet($this, $organizer, $organization, $file->id)->assertRedirect();

    $entry = Activity::query()->where('event', 'submission_file_downloaded')->sole();
    expect($entry->causer_id)->toBe($organizer->id)
        ->and($entry->subject_id)->toBe($file->id)
        ->and($entry->created_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($entry->properties->get('organization_id'))->toBe($organization->id)
        ->and(str_contains((string) json_encode($entry->toArray()), 'confidencial'))->toBeFalse()
        ->and(str_contains((string) json_encode($entry->toArray()), '%PDF'))->toBeFalse();
});

it('CA-007-22: the download name comes only from the uuid and the validated extension', function (): void {
    $organization = Memberships::organization();
    $file = dlFile($organization, originalName: "a\".pdf.exe\r\nX-Evil: 1; filename=\"é.php");
    $organizer = dlUser($organization, OrganizationRole::Organizer);
    $signed = dlRecordSignedUrls();

    dlGet($this, $organizer, $organization, $file->id)->assertRedirect();

    expect($signed[0]['disposition'])->toBe("attachment; filename=\"{$file->id}.pdf\"");
});

it('CA-007-23: forReviewer gives a neutral name and never the original name', function (): void {
    $organization = Memberships::organization();
    $file = dlFile($organization, originalName: 'Ana Perez - propuesta.pdf');

    $data = SubmissionFileData::forReviewer($file, 1);

    expect($data)->toBe([
        'id' => $file->id,
        'name' => 'archivo-1.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
    ])->and(str_contains((string) json_encode($data), 'Ana Perez'))->toBeFalse();
});

it('CA-007-23: forOrganizer shows the original name escaped', function (): void {
    $organization = Memberships::organization();
    $file = dlFile($organization, originalName: '<script>alert(1)</script>.pdf');

    $data = SubmissionFileData::forOrganizer($file);

    expect($data['name'])->toBe('&lt;script&gt;alert(1)&lt;/script&gt;.pdf')
        ->and($data['id'])->toBe($file->id);
});

it('CA-007-24: a pending file (without a submission) answers 404', function (): void {
    $organization = Memberships::organization();
    $file = dlFile($organization, attached: false);
    $organizer = dlUser($organization, OrganizationRole::Organizer);
    $signed = dlRecordSignedUrls();

    dlGet($this, $organizer, $organization, $file->id)->assertNotFound();

    expect($signed)->toHaveCount(0);
});

it('CA-007-17: the policy checks the permission, the organization and the active membership', function (): void {
    $organization = Memberships::organization();
    $other = Memberships::organization();
    $file = dlFile($organization);
    $organizer = dlUser($organization, OrganizationRole::Organizer);
    $deactivated = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer, 'deactivated');

    CurrentOrganization::run($organization, function () use ($organizer, $deactivated, $file): void {
        expect(Gate::forUser($organizer->fresh())->allows('download', $file))->toBeTrue()
            ->and(Gate::forUser($deactivated->fresh())->allows('download', $file))->toBeFalse();
    });

    CurrentOrganization::run($other, function () use ($organizer, $file): void {
        expect(Gate::forUser($organizer->fresh())->allows('download', $file))->toBeFalse();
    });

    expect(Gate::forUser($organizer->fresh())->allows('download', $file))->toBeFalse();
});

it('CA-007-18: the action without organization context fails instead of answering empty', function (): void {
    $organization = Memberships::organization();
    $file = dlFile($organization);
    $organizer = dlUser($organization, OrganizationRole::Organizer);

    expect(fn () => app(DownloadSubmissionFile::class)->handle($organizer, $file->id))
        ->toThrow(MissingOrganizationContext::class);
});
