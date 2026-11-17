<?php

declare(strict_types=1);

use App\Enums\SubmissionOrigin;
use App\Enums\SubmissionStatus;
use App\Models\CallForSubmissions;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionAccessToken;
use App\Models\SubmissionParticipant;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantModels;

uses(RefreshDatabase::class);

/**
 * Copia cruda de una fila existente, lista para insertarse de nuevo con otro id y
 * otra clave de idempotencia. Las columnas json ya vienen como texto.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function schemaRow(string $table, string $id, array $overrides = []): array
{
    $row = (array) DB::table($table)->where('id', $id)->first();

    $row['id'] = (string) Str::uuid();
    if (array_key_exists('idempotency_key', $row) && $row['idempotency_key'] !== null) {
        $row['idempotency_key'] = (string) Str::uuid();
    }
    if (array_key_exists('token_hash', $row)) {
        $row['token_hash'] = hash('sha256', Str::random(30));
    }

    return array_merge($row, $overrides);
}

/**
 * Inserta y dice si la base lo rechazó. Savepoint: PostgreSQL aborta la
 * transacción de la prueba tras un error.
 *
 * @param  array<string, mixed>  $row
 */
function schemaRejects(string $table, array $row): bool
{
    try {
        DB::transaction(fn (): bool => DB::table($table)->insert($row));
    } catch (QueryException) {
        return true;
    }

    return false;
}

it('creates a call submission with typed casts and its relations', function (): void {
    $submission = Submission::factory()->create();

    $fresh = Submission::query()->withoutGlobalScopes()->findOrFail($submission->id);

    expect($fresh->origin)->toBe(SubmissionOrigin::Call)
        ->and($fresh->status)->toBe(SubmissionStatus::Submitted)
        ->and($fresh->version)->toBe(1)
        ->and($fresh->form_snapshot)->toBeArray()
        ->and($fresh->submitted_at)->toBeInstanceOf(CarbonInterface::class)
        ->and(CurrentOrganization::run(Organization::query()->findOrFail($fresh->organization_id), fn () => [$fresh->call, $fresh->track]))
        ->each->toBeInstanceOf(Model::class);
});

it('creates an invited submission without call or consent', function (): void {
    $submission = Submission::factory()->invited()->create();

    expect($submission->origin)->toBe(SubmissionOrigin::Invited)
        ->and($submission->call_for_submissions_id)->toBeNull()
        ->and($submission->consented_at)->toBeNull()
        ->and($submission->registered_by_user_id)->not->toBeNull();
});

it('CA-006-19: rejects a submission that points to another organization', function (string $column): void {
    $a = Submission::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $b = Submission::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $row = schemaRow('submissions', $b->id, [$column => $a->{$column}]);

    expect(schemaRejects('submissions', $row))->toBeTrue();
})->with(['event_id', 'call_for_submissions_id', 'submission_type_id', 'form_definition_id', 'track_id', 'privacy_notice_version_id']);

it('CA-006-19: rejects a participant or a token that points to another organization', function (): void {
    $a = SubmissionParticipant::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $b = SubmissionParticipant::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    expect(schemaRejects('submission_participants', schemaRow('submission_participants', $b->id, [
        'submission_id' => $a->submission_id,
        'position' => 2,
        'is_primary' => false,
    ])))->toBeTrue()
        ->and(schemaRejects('submission_participants', schemaRow('submission_participants', $b->id, [
            'contact_id' => $a->contact_id,
            'position' => 2,
            'is_primary' => false,
        ])))->toBeTrue();

    $tokenB = SubmissionAccessToken::factory()->create(['organization_id' => $b->organization_id]);

    expect(schemaRejects('submission_access_tokens', schemaRow('submission_access_tokens', $tokenB->id, [
        'submission_id' => $a->submission_id,
    ])))->toBeTrue();
});

it('accepts the seven statuses of the glossary and rejects any other', function (): void {
    $base = Submission::factory()->create();

    foreach (SubmissionStatus::cases() as $status) {
        $row = schemaRow('submissions', $base->id, [
            'status' => $status->value,
            'withdrawn_at' => $status === SubmissionStatus::Withdrawn ? now() : null,
        ]);

        expect(schemaRejects('submissions', $row))->toBeFalse($status->value);
    }

    expect(SubmissionStatus::cases())->toHaveCount(7)
        ->and(schemaRejects('submissions', schemaRow('submissions', $base->id, ['status' => 'draft'])))->toBeTrue();
});

it('requires withdrawn_at exactly when the status is withdrawn', function (): void {
    $base = Submission::factory()->create();

    expect(schemaRejects('submissions', schemaRow('submissions', $base->id, ['status' => 'withdrawn'])))->toBeTrue()
        ->and(schemaRejects('submissions', schemaRow('submissions', $base->id, ['withdrawn_at' => now()])))->toBeTrue();
});

it('CA-018-29: rejects an unknown origin and inconsistent origin columns', function (): void {
    $call = Submission::factory()->create();
    $invited = Submission::factory()->invited()->create(['organization_id' => $call->organization_id]);

    expect(schemaRejects('submissions', schemaRow('submissions', $call->id, ['origin' => 'walk_in'])))->toBeTrue()
        // Invitado con convocatoria.
        ->and(schemaRejects('submissions', schemaRow('submissions', $invited->id, ['call_for_submissions_id' => $call->call_for_submissions_id])))->toBeTrue()
        // Invitado con consentimiento.
        ->and(schemaRejects('submissions', schemaRow('submissions', $invited->id, ['consented_at' => now()])))->toBeTrue()
        // Invitado sin quien lo registró o sin la declaración.
        ->and(schemaRejects('submissions', schemaRow('submissions', $invited->id, ['registered_by_user_id' => null])))->toBeTrue()
        ->and(schemaRejects('submissions', schemaRow('submissions', $invited->id, ['authorization_declared_at' => null])))->toBeTrue()
        // Convocatoria sin convocatoria, sin consentimiento o sin versión del aviso.
        ->and(schemaRejects('submissions', schemaRow('submissions', $call->id, ['call_for_submissions_id' => null])))->toBeTrue()
        ->and(schemaRejects('submissions', schemaRow('submissions', $call->id, ['consented_at' => null])))->toBeTrue()
        ->and(schemaRejects('submissions', schemaRow('submissions', $call->id, ['privacy_notice_version_id' => null])))->toBeTrue()
        // Convocatoria con datos propios del invitado.
        ->and(schemaRejects('submissions', schemaRow('submissions', $call->id, ['registered_by_user_id' => $invited->registered_by_user_id])))->toBeTrue()
        // Las filas coherentes sí pasan.
        ->and(schemaRejects('submissions', schemaRow('submissions', $call->id)))->toBeFalse()
        ->and(schemaRejects('submissions', schemaRow('submissions', $invited->id)))->toBeFalse();
});

it('keeps idempotency keys unique per organization only', function (): void {
    $a = Submission::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $b = Submission::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    // Misma organización y misma clave: rechazada.
    expect(schemaRejects('submissions', schemaRow('submissions', $a->id, ['idempotency_key' => $a->idempotency_key])))->toBeTrue()
        // Otra organización con la misma clave: aceptada.
        ->and(schemaRejects('submissions', schemaRow('submissions', $b->id, ['idempotency_key' => $a->idempotency_key])))->toBeFalse();
});

it('allows several invited submissions without idempotency key', function (): void {
    $first = Submission::factory()->invited()->create();
    Submission::factory()->invited()->create(['organization_id' => $first->organization_id]);

    expect(Submission::query()->withoutGlobalScopes()->where('organization_id', $first->organization_id)->count())->toBe(2);
});

it('limits the form snapshot to 64 KB', function (): void {
    $base = Submission::factory()->create();

    $big = json_encode(['blob' => str_repeat('a', 70000)], JSON_THROW_ON_ERROR);

    expect(schemaRejects('submissions', schemaRow('submissions', $base->id, ['form_snapshot' => $big])))->toBeTrue();
});

it('keeps submissions as evidence: soft delete and restrictive track key', function (): void {
    $submission = Submission::factory()->create();
    $trackId = $submission->track_id;

    expect(fn () => DB::transaction(fn () => DB::table('tracks')->where('id', $trackId)->delete()))->toThrow(QueryException::class);

    $submission->delete();

    expect(Submission::query()->withoutGlobalScopes()->withTrashed()->whereKey($submission->id)->exists())->toBeTrue()
        ->and(Submission::query()->withoutGlobalScopes()->whereKey($submission->id)->whereNull('deleted_at')->exists())->toBeFalse();
});

it('allows a single primary participant per submission and unique positions', function (): void {
    $primary = SubmissionParticipant::factory()->create();
    $contact = Contact::factory()->create(['organization_id' => $primary->organization_id]);

    $second = schemaRow('submission_participants', $primary->id, [
        'contact_id' => $contact->id,
        'email' => 'otra@example.test',
        'position' => 2,
    ]);

    // Otro principal en la misma propuesta: rechazado.
    expect(schemaRejects('submission_participants', $second))->toBeTrue()
        // Un participante secundario: aceptado.
        ->and(schemaRejects('submission_participants', array_merge($second, ['is_primary' => false])))->toBeFalse()
        // Misma posición que otro participante: rechazado.
        ->and(schemaRejects('submission_participants', array_merge($second, ['id' => (string) Str::uuid(), 'is_primary' => false])))->toBeTrue();
});

it('stores only a unique hash for access tokens', function (): void {
    $token = SubmissionAccessToken::factory()->create();

    expect(schemaRejects('submission_access_tokens', schemaRow('submission_access_tokens', $token->id, ['token_hash' => $token->token_hash])))->toBeTrue()
        ->and(strlen($token->token_hash))->toBe(64)
        ->and($token->consumed_at)->toBeNull()
        ->and($token->expires_at->isFuture())->toBeTrue();
});

it('gives calls a participant range of 1 to 5 by default and validates it', function (): void {
    $call = CallForSubmissions::factory()->create();
    $fresh = CallForSubmissions::query()->withoutGlobalScopes()->findOrFail($call->id);

    expect($fresh->min_participants)->toBe(1)
        ->and($fresh->max_participants)->toBe(5);

    $update = static fn (array $values): bool => DB::transaction(
        fn (): bool => DB::table('call_for_submissions')->where('id', $call->id)->update($values) === 1,
    );

    expect(fn () => $update(['min_participants' => 0]))->toThrow(QueryException::class)
        ->and(fn () => $update(['min_participants' => 3, 'max_participants' => 2]))->toThrow(QueryException::class)
        ->and($update(['min_participants' => 2, 'max_participants' => 2]))->toBeTrue();
});

it('CA-018-30: tenancy guardians cover the submission models', function (): void {
    $scoped = TenantModels::scoped();

    expect($scoped)->toContain(Submission::class)
        ->toContain(SubmissionParticipant::class)
        ->toContain(SubmissionAccessToken::class);
});
