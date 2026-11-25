<?php

declare(strict_types=1);

use App\Enums\FormDefinitionStatus;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\Track;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Cache::flush();
    Storage::fake(config('filesystems.private_uploads_disk'));
    config([
        'services.turnstile.site_key' => 'site-publica',
        'services.turnstile.secret' => 'secreta-solo-servidor',
        'services.turnstile.verify_url' => 'https://turnstile.test/siteverify',
    ]);
    Http::fake(['turnstile.test/*' => Http::response(['success' => true])]);
    // Dentro de la ventana de la convocatoria del factory (2027-01-10 a 2027-02-15).
    $this->travelTo('2027-01-20 12:00:00');
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Convocatoria abierta con un campo de archivo, un eje y un aviso vigente; usa la sesión real del formulario.
 *
 * @return array{org: Organization, call: CallForSubmissions, track: Track}
 */
function uptScenario(): array
{
    $org = Organization::factory()->create(['slug' => 'parques']);
    CurrentOrganization::set($org);
    $event = Event::factory()->published()->create(['organization_id' => $org->id, 'slug' => 'congreso', 'default_locale' => 'es']);
    $form = FormDefinition::factory()->create([
        'organization_id' => $org->id,
        'event_id' => $event->id,
        'status' => FormDefinitionStatus::Published,
        'published_at' => now(),
        'version' => 1,
        'fields' => [
            ['key' => 'deck', 'type' => 'file', 'required' => false, 'position' => 1, 'label' => ['es' => 'Presentacion'], 'help' => [], 'config' => ['maxFiles' => 3], 'retiredAt' => null],
        ],
    ]);
    $call = CallForSubmissions::factory()->published()->create([
        'organization_id' => $org->id,
        'event_id' => $event->id,
        'form_definition_id' => $form->id,
        'slug' => 'ponencias',
        'locales' => ['es'],
    ]);
    $track = Track::factory()->create(['organization_id' => $org->id, 'event_id' => $event->id]);
    PrivacyNotice::factory()->published()->create(['organization_id' => $org->id, 'event_id' => $event->id]);

    return ['org' => $org, 'call' => $call, 'track' => $track];
}

/**
 * Abre el formulario: el servidor emite el token de carga a esta sesión.
 *
 * @return array<string, mixed>
 */
function uptOpenForm(mixed $test): array
{
    return $test->get('/parques/events/congreso/call/ponencias/submit')->assertOk()->viewData('page')['props']['form'];
}

/** @return TestResponse<JsonResponse> */
function uptUpload(mixed $test, CallForSubmissions $call, ?string $token): TestResponse
{
    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    return $test->withHeaders(['Accept' => 'application/json'])->post(
        "/parques/events/congreso/call/{$call->id}/files",
        ['file' => UploadedFile::fake()->createWithContent('a.pdf', $pdf), 'form_field_key' => 'deck', 'upload_token' => $token],
    );
}

function uptStored(): int
{
    return SubmissionFile::query()->withoutGlobalScopes()->count();
}

it('CA-006-27: the server issues the upload token with the form and the session keeps only its hash', function (): void {
    $ctx = uptScenario();

    $token = uptOpenForm($this)['uploadToken'];

    expect($token)->toBeString()
        ->and($token)->not->toBe('')
        ->and(session()->get('upload_tokens.'.$ctx['call']->id))->toBe([hash('sha256', $token)])
        ->and(json_encode(session()->all()))->not->toContain($token);

    uptUpload($this, $ctx['call'], $token)->assertCreated();
    expect(uptStored())->toBe(1);
});

it('CA-006-27: rejects an upload with 403 UPLOAD_TOKEN_INVALID without a token, with an unknown one or with one of another session', function (): void {
    $ctx = uptScenario();
    $foreign = uptOpenForm($this)['uploadToken'];
    $this->flushSession();
    uptOpenForm($this);

    foreach ([null, '', 'token-inexistente-0123456789abcdef', $foreign] as $token) {
        uptUpload($this, $ctx['call'], $token)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'UPLOAD_TOKEN_INVALID');
    }

    expect(uptStored())->toBe(0)
        ->and(Storage::disk(config('filesystems.private_uploads_disk'))->allFiles())->toBe([]);
});

it('CA-006-27: an upload bound to another token cannot be attached when submitting the proposal', function (): void {
    $ctx = uptScenario();
    $first = uptOpenForm($this);
    $fileId = uptUpload($this, $ctx['call'], $first['uploadToken'])->assertCreated()->json('data.id');

    $this->flushSession();
    $second = uptOpenForm($this);

    $this->post('/parques/events/congreso/call/ponencias/submit', [
        'idempotency_key' => $second['idempotencyKey'],
        'upload_token' => $second['uploadToken'],
        'form_version' => $second['version'],
        'locale' => 'es',
        'title' => 'Mi propuesta',
        'track_id' => $ctx['track']->id,
        'answers' => ['deck' => [$fileId]],
        'participants' => [['first_name' => 'Ana', 'last_name' => 'Perez', 'email' => 'ana@example.test', 'country' => 'MX', 'state' => 'CDMX', 'city' => 'Ciudad de México', 'phone' => '+52 55 1234 5678', 'job_title' => 'Dra.', 'company' => 'ACME', 'bio' => 'Semblanza profesional', 'is_primary' => true]],
        'consent' => true,
        'privacy_notice_version_id' => $second['privacyNotice']['id'],
        'turnstile_token' => 'token-unico',
    ])->assertUnprocessable()->assertJsonValidationErrors('answers.deck');

    expect(Submission::query()->count())->toBe(0);
});

it('CA-006-28: answers 429 and stores nothing when a token exceeds its quota, with the limit read from the configuration', function (): void {
    $ctx = uptScenario();
    config(['submissions.upload_quota.per_token' => ['attempts' => 2, 'decay_seconds' => 3600]]);
    $token = uptOpenForm($this)['uploadToken'];

    uptUpload($this, $ctx['call'], $token)->assertCreated();
    uptUpload($this, $ctx['call'], $token)->assertCreated();
    $response = uptUpload($this, $ctx['call'], $token);

    $response->assertStatus(429)->assertJsonPath('error.code', 'UPLOAD_QUOTA_EXCEEDED')->assertHeader('Retry-After');
    expect(uptStored())->toBe(2)
        ->and(Storage::disk(config('filesystems.private_uploads_disk'))->allFiles())->toHaveCount(2);
});

it('CA-006-28: answers 429 and stores nothing when an IP exceeds its quota across tokens, with the limit read from the configuration', function (): void {
    $ctx = uptScenario();
    config(['submissions.upload_quota.per_ip' => ['attempts' => 2, 'decay_seconds' => 3600]]);

    foreach (range(1, 2) as $_) {
        $this->flushSession();
        uptUpload($this, $ctx['call'], uptOpenForm($this)['uploadToken'])->assertCreated();
    }
    $this->flushSession();
    uptUpload($this, $ctx['call'], uptOpenForm($this)['uploadToken'])->assertStatus(429);

    expect(uptStored())->toBe(2);
});

it('CA-006-28: the default quotas are those of plan 007 (10 per token and 30 per IP each hour)', function (): void {
    expect(config('submissions.upload_quota.per_token'))->toBe(['attempts' => 10, 'decay_seconds' => 3600])
        ->and(config('submissions.upload_quota.per_ip'))->toBe(['attempts' => 30, 'decay_seconds' => 3600]);
});
