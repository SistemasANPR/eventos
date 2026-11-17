<?php

declare(strict_types=1);

use App\Actions\Submissions\AttachSubmissionFile;
use App\Enums\CallStatus;
use App\Enums\SubmissionFileStatus;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\SubmissionFile;
use App\Support\Files\FileFieldRegistry;
use App\Support\Files\PrivateUploads;
use App\Support\Files\UploadTokenVerifier;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeSubmissionForm;

uses(RefreshDatabase::class);

const UPLOAD_TOKEN = 'tok-0123456789abcdef0123456789abcdef';

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake(config('filesystems.private_uploads_disk'));
    FakeSubmissionForm::install();
    // Dentro de la ventana de la convocatoria del factory (2027-01-10 a 2027-02-15).
    $this->travelTo('2027-01-20 12:00:00');
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return string PDF mínimo, con relleno hasta `$bytes` si se indica */
function uplPdf(int $bytes = 0): string
{
    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    return $bytes > strlen($pdf) ? $pdf.str_repeat('0', $bytes - strlen($pdf)) : $pdf;
}

function uplPng(): string
{
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function uplJpg(): string
{
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

/** @param  array<string, string>  $entries nombre de entrada => contenido */
function uplZip(array $entries): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

function uplPptx(): string
{
    return uplZip(['[Content_Types].xml' => '<Types/>', 'ppt/presentation.xml' => '<p/>']);
}

/** @return array{0: Organization, 1: Event, 2: CallForSubmissions} */
function uplScenario(string $slug = 'congreso'): array
{
    $organization = Organization::factory()->create();
    $event = Event::factory()->published()->create(['organization_id' => $organization->id, 'slug' => $slug]);
    $call = CallForSubmissions::factory()->published()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);

    return [$organization, $event, $call];
}

/**
 * @param  array<string, mixed>  $fields
 * @return TestResponse<JsonResponse>
 */
function uplPost(mixed $test, Organization $organization, Event $event, CallForSubmissions $call, UploadedFile $file, array $fields = []): TestResponse
{
    return $test->withHeaders(['Accept' => 'application/json'])->post(
        "/{$organization->slug}/events/{$event->slug}/call/{$call->id}/files",
        ['file' => $file, 'form_field_key' => 'presentation', 'upload_token' => UPLOAD_TOKEN, ...$fields],
    );
}

function uplFile(string $name, string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function uplDisk(): Filesystem
{
    return Storage::disk(config('filesystems.private_uploads_disk'));
}

/**
 * @param  list<string>  $fileIds
 * @return array<string, list<string>> errores de validación, vacío si se adjuntó
 */
function uplAttachErrors(CallForSubmissions $call, string $submissionId, string $field, array $fileIds): array
{
    try {
        app(AttachSubmissionFile::class)->handle($call, $submissionId, UPLOAD_TOKEN, $field, $fileIds);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

function uplNothingStored(): void
{
    expect(uplDisk()->allFiles())->toBe([])
        ->and(SubmissionFile::query()->withoutGlobalScopes()->count())->toBe(0);
}

it('CA-007-1: stores a valid PDF in the private disk with a generated name, pending and the token hash', function (): void {
    [$organization, $event, $call] = uplScenario();

    $response = uplPost($this, $organization, $event, $call, uplFile('informe final.pdf', uplPdf(1024 * 1024)));

    $response->assertCreated()->assertJsonPath('success', true)->assertJsonStructure(['success', 'data' => ['id']]);
    $file = SubmissionFile::query()->withoutGlobalScopes()->sole();
    $path = $file->storage_path;

    expect($response->json('data.id'))->toBe($file->id)
        ->and($path)->toMatch("#^organizations/{$organization->id}/events/{$event->id}/files/[0-9a-f-]{36}\\.pdf$#")
        ->and($path)->not->toContain('informe')
        ->and(uplDisk()->exists($path))->toBeTrue()
        ->and($file->status)->toBe(SubmissionFileStatus::Pending)
        ->and($file->submission_id)->toBeNull()
        ->and($file->upload_token_hash)->toBe(hash('sha256', UPLOAD_TOKEN))
        ->and($file->mime_type)->toBe('application/pdf')
        ->and($file->size_bytes)->toBe(1024 * 1024)
        ->and($file->sha256)->toBe(hash('sha256', uplPdf(1024 * 1024)))
        ->and($file->original_name)->toBe('informe final.pdf')
        ->and($file->call_id)->toBe($call->id)
        ->and($file->form_field_key)->toBe('presentation');
    expect(json_encode($file->getAttributes()))->not->toContain(UPLOAD_TOKEN);
});

it('CA-007-2: rejects a PHP script renamed as a PDF by its real content and leaves nothing behind', function (): void {
    [$organization, $event, $call] = uplScenario();

    $response = uplPost($this, $organization, $event, $call, uplFile('informe.pdf', '<?php system($_GET["c"]); ?>'));

    $response->assertStatus(422)->assertJsonPath('success', false)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    uplNothingStored();
});

it('CA-007-3: rejects php, phtml, phar, html, svg, exe and zip even when the header claims an allowed type', function (string $name): void {
    [$organization, $event, $call] = uplScenario();

    uplPost($this, $organization, $event, $call, uplFile($name, uplPdf()))->assertStatus(422);

    uplNothingStored();
})->with(['a.php', 'a.phtml', 'a.phar', 'a.html', 'a.svg', 'a.exe', 'a.zip', 'a.PHP', 'a.pdf.php', 'a']);

it('CA-007-3: rejects the executable extensions on a PNG body too', function (string $name): void {
    [$organization, $event, $call] = uplScenario();

    uplPost($this, $organization, $event, $call, uplFile($name, uplPng()))->assertStatus(422);

    uplNothingStored();
})->with(['a.php', 'a.phtml', 'a.phar', 'a.html', 'a.svg', 'a.exe', 'a.zip']);

it('CA-007-4: accepts exactly 25 MB and rejects one byte more naming the limit', function (): void {
    [$organization, $event, $call] = uplScenario();
    $limit = 25 * 1024 * 1024;

    uplPost($this, $organization, $event, $call, uplFile('limite.pdf', uplPdf($limit)))->assertCreated();
    expect(SubmissionFile::query()->withoutGlobalScopes()->count())->toBe(1);

    $response = uplPost($this, $organization, $event, $call, uplFile('excede.pdf', uplPdf($limit + 1)));

    $response->assertStatus(422);
    expect($response->json('error.message'))->toContain('25 MB')
        ->and(SubmissionFile::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(uplDisk()->allFiles())->toHaveCount(1);
});

it('CA-007-5: keeps the original name only as display data and never as a path', function (): void {
    [$organization, $event, $call] = uplScenario();

    uplPost($this, $organization, $event, $call, uplFile("..\\..\\etc\\pas\x00s\x07wd\u{202E}.pdf", uplPdf()))->assertCreated();

    $file = SubmissionFile::query()->withoutGlobalScopes()->sole();
    expect($file->original_name)->toBe('passwd.pdf')
        ->and($file->storage_path)->not->toContain('..', 'passwd')
        ->and(uplDisk()->allFiles())->toBe([$file->storage_path]);
});

it('CA-007-5: sanitizes traversal segments and control characters of the original name', function (): void {
    expect(PrivateUploads::sanitizeName('../../a/b\\..\\evil.pdf'))->toBe('evil.pdf')
        ->and(PrivateUploads::sanitizeName("tab\there\r\nline.pdf"))->toBe('tabhereline.pdf')
        ->and(PrivateUploads::sanitizeName('..'))->toBe('archivo')
        ->and(PrivateUploads::sanitizeName(str_repeat('a', 400).'.pdf'))->toHaveLength(255);
});

it('CA-007-6: answers 422 for a call that is not open and stores nothing', function (CallStatus $status, ?string $opensAt, ?string $closesAt): void {
    [$organization, $event, $call] = uplScenario();
    $call->forceFill(array_filter(['status' => $status, 'opens_at' => $opensAt, 'closes_at' => $closesAt]))->save();

    uplPost($this, $organization, $event, $call, uplFile('a.pdf', uplPdf()))->assertStatus(422);

    uplNothingStored();
})->with([
    'draft' => [CallStatus::Draft, null, null],
    'paused' => [CallStatus::Paused, null, null],
    'closed by hand' => [CallStatus::Closed, null, null],
    'closed by date' => [CallStatus::Published, '2026-12-01 00:00:00', '2027-01-20 12:00:00'],
    'scheduled' => [CallStatus::Published, '2027-01-20 12:00:01', '2027-02-15 00:00:00'],
]);

it('CA-007-6: answers 422 for a file field that does not exist in the form and stores nothing', function (): void {
    [$organization, $event, $call] = uplScenario();

    uplPost($this, $organization, $event, $call, uplFile('a.pdf', uplPdf()), ['form_field_key' => 'inexistente'])
        ->assertStatus(422)
        ->assertJsonPath('error.field', 'form_field_key');

    uplNothingStored();
});

it('CA-007-6: the default form registry knows no file fields until the form builder exists', function (): void {
    app()->forgetInstance(FileFieldRegistry::class);
    app()->forgetInstance(UploadTokenVerifier::class);
    [$organization, $event, $call] = uplScenario();

    // Sin implementación real del formulario (plan 005) y de su sesión (plan 006) se deniega.
    uplPost($this, $organization, $event, $call, uplFile('a.pdf', uplPdf()))->assertForbidden();

    uplNothingStored();
});

it('CA-007-7: accepts a PPTX with the OOXML structure', function (): void {
    [$organization, $event, $call] = uplScenario();

    uplPost($this, $organization, $event, $call, uplFile('diapositivas.pptx', uplPptx()))->assertCreated();

    $file = SubmissionFile::query()->withoutGlobalScopes()->sole();
    expect($file->storage_path)->toEndWith('.pptx')
        ->and(uplDisk()->exists($file->storage_path))->toBeTrue();
});

it('CA-007-7: accepts JPG and PNG images', function (string $name, string $content, string $extension): void {
    [$organization, $event, $call] = uplScenario();

    uplPost($this, $organization, $event, $call, uplFile($name, $content))->assertCreated();

    expect(SubmissionFile::query()->withoutGlobalScopes()->sole()->storage_path)->toEndWith(".{$extension}");
})->with([
    'png' => ['poster.png', fn () => uplPng(), 'png'],
    'jpg' => ['poster.jpg', fn () => uplJpg(), 'jpg'],
    'jpeg' => ['poster.JPEG', fn () => uplJpg(), 'jpg'],
]);

it('CA-007-8: rejects an arbitrary ZIP renamed as a PPTX', function (string $case): void {
    [$organization, $event, $call] = uplScenario();
    $zip = match ($case) {
        'sin content types' => uplZip(['ppt/presentation.xml' => '<p/>']),
        'sin ppt' => uplZip(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w/>']),
        default => uplZip(['readme.txt' => 'hola']),
    };

    uplPost($this, $organization, $event, $call, uplFile('diapositivas.pptx', $zip))->assertStatus(422);

    uplNothingStored();
})->with(['sin content types', 'sin ppt', 'zip cualquiera']);

it('CA-007-8: rejects a text file renamed as a PPTX', function (): void {
    [$organization, $event, $call] = uplScenario();

    uplPost($this, $organization, $event, $call, uplFile('diapositivas.pptx', 'no soy un zip'))->assertStatus(422);

    uplNothingStored();
});

it('CA-007-9: rejects polyglots with a valid PDF, JPG or PNG header and executable code or a concatenated ZIP', function (string $name, string $body): void {
    [$organization, $event, $call] = uplScenario();

    uplPost($this, $organization, $event, $call, uplFile($name, $body))->assertStatus(422);

    uplNothingStored();
})->with([
    'pdf with php' => ['a.pdf', fn () => uplPdf()."<?php system('id'); ?>"],
    'pdf with PHP in capitals' => ['a.pdf', fn () => uplPdf().'<?PHP echo 1; ?>'],
    'png with php' => ['a.png', fn () => uplPng().'<?php system("id"); ?>'],
    'jpg with php' => ['a.jpg', fn () => uplJpg().'<?php system("id"); ?>'],
    'pdf with zip' => ['a.pdf', fn () => uplPdf().uplZip(['evil.php' => '<?php //'])],
    'png with zip' => ['a.png', fn () => uplPng().uplZip(['a.txt' => 'x'])],
    'jpg with zip' => ['a.jpg', fn () => uplJpg().uplZip(['a.txt' => 'x'])],
]);

it('CA-007-10: answers 403 without an upload token or with one the form did not issue, and stores nothing', function (?string $token): void {
    FakeSubmissionForm::install([UPLOAD_TOKEN]);
    [$organization, $event, $call] = uplScenario();
    $fields = $token === null ? ['upload_token' => null] : ['upload_token' => $token];

    $response = uplPost($this, $organization, $event, $call, uplFile('a.pdf', uplPdf()), $fields);

    $response->assertForbidden()->assertJsonPath('error.code', 'UPLOAD_TOKEN_INVALID');
    uplNothingStored();
})->with([
    'missing' => [null],
    'empty' => [''],
    'foreign' => ['tok-de-otra-sesion-0123456789abcdef'],
]);

it('CA-007-11: refuses to attach a pending file uploaded with the token of another session, without revealing it exists', function (): void {
    [, , $call] = uplScenario();
    $file = SubmissionFile::factory()->create(['call_id' => $call->id, 'upload_token_hash' => hash('sha256', 'otro-token-0123456789abcdef0123456789')]);
    $submissionId = (string) Str::uuid();

    $foreign = uplAttachErrors($call, $submissionId, 'presentation', [$file->id]);
    $missing = uplAttachErrors($call, $submissionId, 'presentation', [(string) Str::uuid()]);

    expect($foreign)->not->toBe([])->and($foreign)->toBe($missing);

    $fresh = SubmissionFile::query()->withoutGlobalScopes()->findOrFail($file->id);
    expect($fresh->status)->toBe(SubmissionFileStatus::Pending)->and($fresh->submission_id)->toBeNull();
});

it('CA-007-11: attaches the pending files of the same session to the submission', function (): void {
    [, , $call] = uplScenario();
    $files = SubmissionFile::factory()->count(2)->create(['call_id' => $call->id, 'upload_token_hash' => hash('sha256', UPLOAD_TOKEN)]);
    $submissionId = (string) Str::uuid();

    app(AttachSubmissionFile::class)->handle($call, $submissionId, UPLOAD_TOKEN, 'presentation', $files->pluck('id')->all());

    foreach ($files as $file) {
        $fresh = SubmissionFile::query()->withoutGlobalScopes()->findOrFail($file->id);
        expect($fresh->status)->toBe(SubmissionFileStatus::Attached)->and($fresh->submission_id)->toBe($submissionId);
    }
});

it('CA-007-12: refuses a file already attached to another submission and keeps the first link', function (): void {
    [, , $call] = uplScenario();
    $first = (string) Str::uuid();
    $file = SubmissionFile::factory()->attachedTo($first)->create(['call_id' => $call->id, 'upload_token_hash' => hash('sha256', UPLOAD_TOKEN)]);

    expect(fn () => app(AttachSubmissionFile::class)->handle($call, (string) Str::uuid(), UPLOAD_TOKEN, 'presentation', [$file->id]))
        ->toThrow(ValidationException::class);

    expect(SubmissionFile::query()->withoutGlobalScopes()->findOrFail($file->id)->submission_id)->toBe($first);
});

it('CA-007-13: answers 429 on the eleventh upload with the same token and stores nothing more', function (): void {
    [$organization, $event, $call] = uplScenario();

    foreach (range(1, 10) as $_) {
        uplPost($this, $organization, $event, $call, uplFile('a.pdf', uplPdf()))->assertCreated();
    }
    $response = uplPost($this, $organization, $event, $call, uplFile('a.pdf', uplPdf()));

    $response->assertStatus(429)->assertJsonPath('error.code', 'UPLOAD_QUOTA_EXCEEDED');
    expect(SubmissionFile::query()->withoutGlobalScopes()->count())->toBe(10)
        ->and(uplDisk()->allFiles())->toHaveCount(10);
});

it('CA-007-13: answers 429 on the 31st upload from the same IP even with different tokens', function (): void {
    [$organization, $event, $call] = uplScenario();

    foreach (range(1, 30) as $i) {
        uplPost($this, $organization, $event, $call, uplFile('a.pdf', uplPdf()), ['upload_token' => "tok-{$i}-0123456789abcdef0123456789"])->assertCreated();
    }
    $response = uplPost($this, $organization, $event, $call, uplFile('a.pdf', uplPdf()), ['upload_token' => 'tok-31-0123456789abcdef0123456789']);

    $response->assertStatus(429);
    expect(SubmissionFile::query()->withoutGlobalScopes()->count())->toBe(30);
});

it('CA-007-13: the quota is per organization and per hour', function (): void {
    [$organization, $event, $call] = uplScenario();
    foreach (range(1, 10) as $_) {
        uplPost($this, $organization, $event, $call, uplFile('a.pdf', uplPdf()))->assertCreated();
    }
    uplPost($this, $organization, $event, $call, uplFile('a.pdf', uplPdf()))->assertStatus(429);

    [$other, $otherEvent, $otherCall] = uplScenario();
    uplPost($this, $other, $otherEvent, $otherCall, uplFile('a.pdf', uplPdf()))->assertCreated();

    $this->travel(61)->minutes();
    uplPost($this, $organization, $event, $call, uplFile('a.pdf', uplPdf()))->assertCreated();
});

it('CA-007-14: refuses a fourth file in a field limited to three', function (): void {
    [, , $call] = uplScenario();
    $hash = hash('sha256', UPLOAD_TOKEN);
    $files = SubmissionFile::factory()->count(4)->create(['call_id' => $call->id, 'upload_token_hash' => $hash]);

    expect(fn () => app(AttachSubmissionFile::class)->handle($call, (string) Str::uuid(), UPLOAD_TOKEN, 'presentation', $files->pluck('id')->all()))
        ->toThrow(ValidationException::class);

    expect(SubmissionFile::query()->withoutGlobalScopes()->where('status', 'attached')->count())->toBe(0);
});

it('CA-007-14: counts the files already attached to the same field of the submission', function (): void {
    [, , $call] = uplScenario();
    $submissionId = (string) Str::uuid();
    SubmissionFile::factory()->count(3)->attachedTo($submissionId)->create(['call_id' => $call->id]);
    $extra = SubmissionFile::factory()->create(['call_id' => $call->id, 'upload_token_hash' => hash('sha256', UPLOAD_TOKEN)]);

    expect(fn () => app(AttachSubmissionFile::class)->handle($call, $submissionId, UPLOAD_TOKEN, 'presentation', [$extra->id]))
        ->toThrow(ValidationException::class);
});

it('CA-007-15: refuses an eleventh file in a submission and names the limit', function (): void {
    [, , $call] = uplScenario();
    $submissionId = (string) Str::uuid();
    foreach (['a', 'b', 'c', 'd'] as $field) {
        SubmissionFile::factory()->count($field === 'd' ? 1 : 3)->attachedTo($submissionId)->create(['call_id' => $call->id, 'form_field_key' => $field]);
    }
    $extra = SubmissionFile::factory()->create(['call_id' => $call->id, 'upload_token_hash' => hash('sha256', UPLOAD_TOKEN), 'form_field_key' => 'e']);

    $errors = uplAttachErrors($call, $submissionId, 'e', [$extra->id]);

    expect(json_encode($errors))->toContain('10');
    expect(SubmissionFile::query()->withoutGlobalScopes()->findOrFail($extra->id)->status)->toBe(SubmissionFileStatus::Pending);
});

it('CA-007-15: refuses a file that takes the submission over 100 MB and names the limit', function (): void {
    [, , $call] = uplScenario();
    $submissionId = (string) Str::uuid();
    SubmissionFile::factory()->attachedTo($submissionId)->create(['call_id' => $call->id, 'size_bytes' => 100 * 1024 * 1024]);
    $extra = SubmissionFile::factory()->create(['call_id' => $call->id, 'upload_token_hash' => hash('sha256', UPLOAD_TOKEN), 'form_field_key' => 'poster', 'size_bytes' => 1]);

    $errors = uplAttachErrors($call, $submissionId, 'poster', [$extra->id]);

    expect(json_encode($errors))->toContain('100 MB');
});

it('CA-007-15: accepts a submission that lands exactly on 10 files and 100 MB', function (): void {
    [, , $call] = uplScenario();
    $submissionId = (string) Str::uuid();
    foreach (['a', 'b', 'c'] as $field) {
        SubmissionFile::factory()->count(3)->attachedTo($submissionId)->create(['call_id' => $call->id, 'form_field_key' => $field, 'size_bytes' => 10 * 1024 * 1024]);
    }
    $extra = SubmissionFile::factory()->create(['call_id' => $call->id, 'upload_token_hash' => hash('sha256', UPLOAD_TOKEN), 'form_field_key' => 'd', 'size_bytes' => 10 * 1024 * 1024]);

    app(AttachSubmissionFile::class)->handle($call, $submissionId, UPLOAD_TOKEN, 'd', [$extra->id]);

    expect(SubmissionFile::query()->withoutGlobalScopes()->findOrFail($extra->id)->status)->toBe(SubmissionFileStatus::Attached);
});

it('answers 404 when the call, the event or the organization of the URL do not match', function (): void {
    [$organization, $event, $call] = uplScenario();
    [$other, $otherEvent, $otherCall] = uplScenario('ajeno');
    $sibling = Event::factory()->published()->create(['organization_id' => $organization->id, 'slug' => 'otro']);

    uplPost($this, $organization, $event, $otherCall, uplFile('a.pdf', uplPdf()))->assertNotFound();
    uplPost($this, $organization, $otherEvent, $call, uplFile('a.pdf', uplPdf()))->assertNotFound();
    uplPost($this, $other, $event, $call, uplFile('a.pdf', uplPdf()))->assertNotFound();
    uplPost($this, $organization, $sibling, $call, uplFile('a.pdf', uplPdf()))->assertNotFound();

    uplNothingStored();
});
