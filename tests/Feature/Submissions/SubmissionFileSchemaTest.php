<?php

declare(strict_types=1);

use App\Models\CallForSubmissions;
use App\Models\Organization;
use App\Models\SubmissionFile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fileRow(CallForSubmissions $call, array $overrides = []): array
{
    $id = (string) Str::uuid();

    return array_merge([
        'id' => $id,
        'organization_id' => $call->organization_id,
        'event_id' => $call->event_id,
        'call_id' => $call->id,
        'form_field_key' => 'presentation',
        'upload_token_hash' => hash('sha256', 'token'),
        'storage_path' => "organizations/{$call->organization_id}/events/{$call->event_id}/files/{$id}.pdf",
        'original_name' => 'a.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 10,
        'sha256' => hash('sha256', $id),
        'status' => 'pending',
        'uploaded_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

/** @param  array<string, mixed>  $row */
function insertFile(array $row): bool
{
    // Savepoint: PostgreSQL aborta la transacción de la prueba tras un error.
    return DB::transaction(fn (): bool => DB::table('submission_files')->insert($row));
}

it('accepts a consistent organization, event and call', function (): void {
    $call = CallForSubmissions::factory()->create();

    expect(insertFile(fileRow($call)))->toBeTrue();
});

it('the composite foreign keys refuse a file whose organization is not the one of its call or event', function (): void {
    $call = CallForSubmissions::factory()->create();
    $other = Organization::factory()->create();

    expect(fn () => insertFile(fileRow($call, ['organization_id' => $other->id])))->toThrow(QueryException::class);
});

it('the database refuses an unknown status, a negative size and an inconsistent attachment', function (array $overrides): void {
    $call = CallForSubmissions::factory()->create();

    expect(fn () => insertFile(fileRow($call, $overrides)))->toThrow(QueryException::class);
})->with([
    'unknown status' => [['status' => 'deleted']],
    'negative size' => [['size_bytes' => -1]],
    'attached without submission' => [['status' => 'attached']],
    'pending with submission' => [['submission_id' => '0f9d2b0e-6a53-4ef4-9f55-0c8a6f0c7a11']],
]);

it('the storage path is unique per organization', function (): void {
    $call = CallForSubmissions::factory()->create();
    $row = fileRow($call);
    insertFile($row);

    expect(fn () => insertFile(fileRow($call, ['storage_path' => $row['storage_path']])))->toThrow(QueryException::class);
});

it('the factory builds files with a consistent organization, event and call', function (): void {
    $file = SubmissionFile::factory()->create();

    $call = CallForSubmissions::query()->withoutGlobalScopes()->findOrFail($file->call_id);

    expect($call->organization_id)->toBe($file->organization_id)
        ->and($call->event_id)->toBe($file->event_id);
});

it('never serializes the upload token hash', function (): void {
    $file = SubmissionFile::factory()->create();

    expect($file->toArray())->not->toHaveKey('upload_token_hash');
});
