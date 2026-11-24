<?php

declare(strict_types=1);

use App\Enums\CallStatus;
use App\Enums\FormDefinitionStatus;
use App\Http\Middleware\SetOrganizationFromRoute;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Models\Submission;
use App\Models\Track;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Cache::flush();
    config([
        'services.turnstile.site_key' => 'site-publica',
        'services.turnstile.secret' => 'secreta-solo-servidor',
        'services.turnstile.verify_url' => 'https://turnstile.test/siteverify',
    ]);
    // Dentro de la ventana de la convocatoria (2027-01-10 15:00 a 2027-02-15 23:00 UTC).
    $this->travelTo('2027-01-20 12:00:00');
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Evento publicado con convocatoria abierta, formulario publicado, un eje y un aviso vigente.
 *
 * @param  array<string, mixed>  $callAttributes
 * @return array{org: Organization, event: Event, call: CallForSubmissions, form: FormDefinition, track: Track, url: string}
 */
function publicFormScenario(string $slug = 'parques', array $callAttributes = [], bool $withNotice = true): array
{
    $org = Organization::factory()->create(['slug' => $slug]);
    CurrentOrganization::set($org);
    $event = Event::factory()->published()->create(['organization_id' => $org->id, 'slug' => 'congreso', 'default_locale' => 'es']);
    $form = FormDefinition::factory()->create([
        'organization_id' => $org->id,
        'event_id' => $event->id,
        'status' => FormDefinitionStatus::Published,
        'published_at' => now(),
        'version' => 3,
        'fields' => [
            ['key' => 'summary', 'type' => 'long_text', 'required' => true, 'position' => 1, 'label' => ['es' => 'Resumen', 'en' => 'Summary'], 'help' => [], 'config' => ['maxLength' => 500], 'retiredAt' => null],
            ['key' => 'old', 'type' => 'short_text', 'required' => false, 'position' => 2, 'label' => ['es' => 'Retirado'], 'help' => [], 'config' => [], 'retiredAt' => '2027-01-01T00:00:00Z'],
        ],
    ]);
    $call = CallForSubmissions::factory()->published()->create($callAttributes + [
        'organization_id' => $org->id,
        'event_id' => $event->id,
        'form_definition_id' => $form->id,
        'slug' => 'ponencias',
        'locales' => ['es', 'en'],
    ]);
    $call->forceFill(['min_participants' => 1, 'max_participants' => 3])->save();
    $track = Track::factory()->create(['organization_id' => $org->id, 'event_id' => $event->id]);

    if ($withNotice) {
        PrivacyNotice::factory()->published()->create(['organization_id' => $org->id, 'event_id' => $event->id]);
    }

    return ['org' => $org, 'event' => $event, 'call' => $call, 'form' => $form, 'track' => $track, 'url' => "/{$slug}/events/congreso/call/ponencias"];
}

/**
 * Abre el formulario (emite la clave y el token de carga a esta sesión) y devuelve sus props.
 *
 * @param  Closure(string): TestResponse<Response>  $get  la petición GET de la prueba (`$this->get(...)`)
 * @param  array{url: string}  $ctx
 * @return array<string, mixed>
 */
function openForm(Closure $get, array $ctx, string $query = ''): array
{
    $page = $get($ctx['url'].'/submit'.$query)->assertOk()->viewData('page');

    return $page['props']['form'];
}

/**
 * @param  array<string, mixed>  $ctx
 * @param  array<string, mixed>  $form  props del formulario
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function formBody(array $ctx, array $form, array $overrides = []): array
{
    return array_replace([
        'idempotency_key' => $form['idempotencyKey'],
        'upload_token' => $form['uploadToken'],
        'form_version' => $form['version'],
        'locale' => 'es',
        'title' => 'Mi propuesta',
        'track_id' => $ctx['track']->id,
        'answers' => ['summary' => 'Un resumen'],
        'participants' => [['first_name' => 'Ana', 'last_name' => 'Pérez', 'email' => 'ana@example.test', 'country' => 'MX', 'state' => 'CDMX', 'city' => 'Ciudad de México', 'phone' => '+52 55 1234 5678', 'job_title' => 'Dra.', 'company' => 'ACME', 'bio' => 'Semblanza profesional', 'is_primary' => true]],
        'consent' => true,
        'privacy_notice_version_id' => $form['privacyNotice']['id'],
        'turnstile_token' => 'token-'.bin2hex(random_bytes(4)),
    ], $overrides);
}

function turnstileOk(): void
{
    Http::fake(['turnstile.test/*' => Http::response(['success' => true])]);
}

function submissionCount(): int
{
    return Submission::query()->count();
}

it('CA-006-55: renders the form with the published fields in the language of the visitor', function (): void {
    $ctx = publicFormScenario();

    $this->get($ctx['url'].'/submit?lang=en')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Calls/Submit')
            ->where('call.state', 'open')
            ->where('call.locale', 'en')
            ->has('form.fields', 1)
            ->where('form.fields.0.key', 'summary')
            ->where('form.fields.0.label.en', 'Summary')
            ->where('form.version', 3)
            ->where('form.minParticipants', 1)
            ->where('form.maxParticipants', 3)
            ->where('form.tracks.0.id', $ctx['track']->id)
            ->where('form.privacyNotice.url', '/parques/events/congreso/privacy')
            ->where('form.uploadUrl', "/parques/events/congreso/call/{$ctx['call']->id}/files")
            ->has('form.idempotencyKey')
            ->has('form.uploadToken')
            ->where('form.translations.forms.renderer.submit', 'Submit proposal'));
});

it('CA-006-55: shows the state without a form when the call is scheduled, paused or closed', function (CallStatus $status, string $state): void {
    $ctx = publicFormScenario(callAttributes: ['status' => $status]);

    $this->get($ctx['url'].'/submit')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Calls/Submit')
            ->where('call.state', $state)
            ->where('form', null));
})->with([
    [CallStatus::Paused, 'paused'],
    [CallStatus::Closed, 'closed'],
]);

it('CA-006-55: a call that has not opened yet and one past its closing show no form', function (): void {
    $ctx = publicFormScenario();

    $this->travelTo('2027-01-01 00:00:00');
    $this->get($ctx['url'].'/submit')->assertInertia(fn (Assert $page) => $page->where('call.state', 'scheduled')->where('form', null));

    $this->travelTo('2027-02-15 23:00:00');
    $this->get($ctx['url'].'/submit')->assertInertia(fn (Assert $page) => $page->where('call.state', 'closed')->where('form', null));
});

it('CA-006-55: an open call without a published privacy notice is not ready and shows no form', function (): void {
    $ctx = publicFormScenario(withNotice: false);

    $this->get($ctx['url'].'/submit')
        ->assertInertia(fn (Assert $page) => $page->where('call.state', 'open')->where('form', null));
});

it('CA-006-55: an open call without a published form is not ready and issues no keys', function (): void {
    $ctx = publicFormScenario();
    $ctx['form']->forceFill(['status' => FormDefinitionStatus::Draft])->save();

    $this->get($ctx['url'].'/submit')
        ->assertInertia(fn (Assert $page) => $page->where('form', null));

    expect(session()->has('idempotency_keys.'.$ctx['call']->id))->toBeFalse();
});

it('CA-006-23: the page props carry the public Turnstile key and never the secret', function (): void {
    $ctx = publicFormScenario();

    $response = $this->get($ctx['url'].'/submit')->assertOk();

    expect($response->viewData('page')['props']['form']['turnstileSiteKey'])->toBe('site-publica')
        ->and($response->getContent())->not->toContain('secreta-solo-servidor');
});

it('CA-006-18: a call of another organization under this URL, and drafts, answer 404', function (): void {
    $a = publicFormScenario('org-a');
    $b = publicFormScenario('org-b');
    $b['call']->forceFill(['slug' => 'solo-de-b'])->save();
    CurrentOrganization::forget();

    $this->get('/org-a/events/congreso/call/solo-de-b/submit')->assertNotFound();
    $this->post('/org-a/events/congreso/call/solo-de-b/submit', [])->assertNotFound();
    $this->get('/org-a/events/congreso/call/solo-de-b/submitted')->assertNotFound();

    CurrentOrganization::set($a['org']);
    $a['call']->forceFill(['status' => CallStatus::Draft])->save();
    CurrentOrganization::forget();

    $this->get($a['url'].'/submit')->assertNotFound();
    $this->postJson($a['url'].'/submit', [])->assertNotFound();
});

it('CA-006-18: public controllers never call withoutOrganizationScope() and the route runs the organization middleware first', function (): void {
    $route = app('router')->getRoutes()->getByName('calls.submit.store');

    expect($route->gatherMiddleware())->toContain(SetOrganizationFromRoute::class)
        ->and(file_get_contents(app_path('Http/Controllers/Public/StoreSubmissionController.php')))->not->toContain('withoutOrganizationScope')
        ->and(file_get_contents(app_path('Http/Controllers/Public/ShowSubmissionFormController.php')))->not->toContain('withoutOrganizationScope');
});

it('CA-006-1: a valid submission is stored and redirects to the confirmation without identifiers in the URL', function (): void {
    $ctx = publicFormScenario();
    turnstileOk();
    $form = openForm($this->get(...), $ctx);

    $response = $this->post($ctx['url'].'/submit', formBody($ctx, $form))
        ->assertRedirect($ctx['url'].'/submitted?lang=es');

    expect(submissionCount())->toBe(1)
        ->and($response->headers->get('Location'))->not->toMatch('/[0-9a-f]{8}-[0-9a-f]{4}|@/');
});

it('CA-006-57: the confirmation shows no personal data and redirects to the call without the session datum', function (): void {
    $ctx = publicFormScenario();
    turnstileOk();

    $this->get($ctx['url'].'/submitted')->assertRedirect($ctx['url']);

    $form = openForm($this->get(...), $ctx);
    $this->post($ctx['url'].'/submit', formBody($ctx, $form));

    $response = $this->get($ctx['url'].'/submitted')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page->component('Calls/Submitted')->where('call.title', fn ($title) => is_string($title)));

    expect((string) $response->getContent())->not->toContain('ana@example.test')->and((string) $response->getContent())->not->toContain('Ana Pérez');

    // Otra sesión (sin el dato) no ve la confirmación.
    $this->flushSession();
    $this->get($ctx['url'].'/submitted')->assertRedirect($ctx['url']);
});

it('CA-006-14: a repeated submission with the same key redirects to the same confirmation and stores one proposal', function (): void {
    $ctx = publicFormScenario();
    turnstileOk();
    $form = openForm($this->get(...), $ctx);
    $body = formBody($ctx, $form);

    $this->post($ctx['url'].'/submit', $body)->assertRedirect($ctx['url'].'/submitted?lang=es');
    $this->post($ctx['url'].'/submit', array_replace($body, ['turnstile_token' => 'otro-token']))->assertRedirect($ctx['url'].'/submitted?lang=es');

    expect(submissionCount())->toBe(1);
});

it('CA-006-21: rejects a missing or invalid Turnstile token with 422 and stores nothing', function (): void {
    $ctx = publicFormScenario();
    Http::fake(['turnstile.test/*' => Http::response(['success' => false])]);
    $form = openForm($this->get(...), $ctx);

    $this->postJson($ctx['url'].'/submit', formBody($ctx, $form, ['turnstile_token' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('turnstile_token');
    $this->postJson($ctx['url'].'/submit', formBody($ctx, $form, ['turnstile_token' => 'token-malo']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('turnstile_token');

    expect(submissionCount())->toBe(0);
});

it('CA-006-22: a Turnstile outage closes access with a retry message, logs without personal data and stores nothing', function (): void {
    $ctx = publicFormScenario();
    Http::fake(fn () => throw new ConnectionException('timeout'));
    $log = Log::spy();
    $form = openForm($this->get(...), $ctx);

    $this->postJson($ctx['url'].'/submit', formBody($ctx, $form))
        ->assertStatus(503)
        ->assertHeader('Retry-After')
        ->assertJson(['code' => 'TURNSTILE_UNAVAILABLE']);

    $log->shouldHaveReceived('warning')->withArgs(fn (string $message): bool => ! str_contains($message, 'ana@example.test'));
    expect(submissionCount())->toBe(0);
});

it('CA-006-24: a Turnstile token that was already used is rejected with 422', function (): void {
    $ctx = publicFormScenario();
    turnstileOk();
    $first = openForm($this->get(...), $ctx);
    $this->post($ctx['url'].'/submit', formBody($ctx, $first, ['turnstile_token' => 'token-unico']))->assertRedirect();

    $second = openForm($this->get(...), $ctx);
    $this->postJson($ctx['url'].'/submit', formBody($ctx, $second, ['title' => 'Otra', 'participants' => [['first_name' => 'Luis', 'last_name' => 'Gómez', 'email' => 'luis@example.test', 'country' => 'MX', 'state' => 'CDMX', 'city' => 'Ciudad de México', 'phone' => '+52 55 1234 5678', 'job_title' => 'Dra.', 'company' => 'ACME', 'bio' => 'Semblanza profesional', 'is_primary' => true]], 'turnstile_token' => 'token-unico']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('turnstile_token');

    expect(submissionCount())->toBe(1);
});

it('CA-006-24: Turnstile is verified only on the final submission, not when the form is opened or a file is uploaded', function (): void {
    $ctx = publicFormScenario();
    Http::fake();

    $this->get($ctx['url'].'/submit')->assertOk();
    $this->postJson("/parques/events/congreso/call/{$ctx['call']->id}/files", [])->assertStatus(422);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'turnstile'));
});

it('CA-006-20: a blocked IP receives 429 with Retry-After and no proposal is created', function (): void {
    $ctx = publicFormScenario();
    turnstileOk();
    config(['submissions.limits.submission_per_ip' => ['attempts' => 1, 'decay_seconds' => 600]]);
    $form = openForm($this->get(...), $ctx);

    $this->post($ctx['url'].'/submit', formBody($ctx, $form))->assertRedirect();
    $this->postJson($ctx['url'].'/submit', formBody($ctx, $form, ['title' => 'Otra', 'participants' => [['first_name' => 'Luis', 'last_name' => 'Gómez', 'email' => 'luis@example.test', 'country' => 'MX', 'state' => 'CDMX', 'city' => 'Ciudad de México', 'phone' => '+52 55 1234 5678', 'job_title' => 'Dra.', 'company' => 'ACME', 'bio' => 'Semblanza profesional', 'is_primary' => true]]]))
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJson(['code' => 'TOO_MANY_REQUESTS']);

    expect(submissionCount())->toBe(1);
});

it('CA-006-26: an email with 3 receipts in the last hour receives 429 and no proposal is created', function (): void {
    $ctx = publicFormScenario();
    turnstileOk();
    config(['submissions.limits.received_per_email' => ['attempts' => 1, 'decay_seconds' => 3600]]);
    $form = openForm($this->get(...), $ctx);

    $this->post($ctx['url'].'/submit', formBody($ctx, $form))->assertRedirect();

    // Otra IP, mismo correo (con otra capitalización).
    $other = openForm($this->get(...), $ctx);
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->postJson($ctx['url'].'/submit', formBody($ctx, $other, ['title' => 'Otra', 'participants' => [['first_name' => 'Ana', 'last_name' => 'Pérez', 'email' => 'ANA@example.test', 'country' => 'MX', 'state' => 'CDMX', 'city' => 'Ciudad de México', 'phone' => '+52 55 1234 5678', 'job_title' => 'Dra.', 'company' => 'ACME', 'bio' => 'Semblanza profesional', 'is_primary' => true]]]))
        ->assertStatus(429);

    expect(submissionCount())->toBe(1);
});

it('CA-006-2: a call closed after the form was opened answers 409 CALL_NOT_OPEN and stores nothing', function (): void {
    $ctx = publicFormScenario();
    turnstileOk();
    $form = openForm($this->get(...), $ctx);

    $this->travelTo('2027-02-15 23:00:00');

    $this->postJson($ctx['url'].'/submit', formBody($ctx, $form))
        ->assertStatus(409)
        ->assertJson(['code' => 'CALL_NOT_OPEN']);

    expect(submissionCount())->toBe(0);
});

it('CA-006-12: an outdated form version answers 409 FORM_OUTDATED and stores nothing', function (): void {
    $ctx = publicFormScenario();
    turnstileOk();
    $form = openForm($this->get(...), $ctx);

    $this->postJson($ctx['url'].'/submit', formBody($ctx, $form, ['form_version' => 2]))
        ->assertStatus(409)
        ->assertJson(['code' => 'FORM_OUTDATED']);

    expect(submissionCount())->toBe(0);
});

it('CA-006-4: reports every error at once, fixed block and answers, keyed by field', function (): void {
    $ctx = publicFormScenario();
    turnstileOk();
    $form = openForm($this->get(...), $ctx);

    $this->postJson($ctx['url'].'/submit', formBody($ctx, $form, ['title' => '', 'answers' => ['summary' => '']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title', 'answers.summary']);

    expect(submissionCount())->toBe(0);
});

it('CA-006-7: the body cannot choose the organization, event, call or status', function (): void {
    $ctx = publicFormScenario();
    $other = publicFormScenario('otra');
    CurrentOrganization::set($ctx['org']);
    turnstileOk();
    $form = openForm($this->get(...), $ctx);

    $this->post($ctx['url'].'/submit', formBody($ctx, $form, [
        'organization_id' => $other['org']->id,
        'event_id' => $other['event']->id,
        'call_for_submissions_id' => $other['call']->id,
        'status' => 'accepted',
    ]))->assertRedirect();

    CurrentOrganization::set($ctx['org']);
    $submission = Submission::query()->sole();

    expect($submission->organization_id)->toBe($ctx['org']->id)
        ->and($submission->call_for_submissions_id)->toBe($ctx['call']->id)
        ->and($submission->status->value)->toBe('submitted');
});
