<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Activity;
use App\Models\Event;
use App\Models\Organization;
use App\Models\SubmissionParticipant;
use App\Models\User;
use App\Support\Auth\AuthorSession;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AuthorFixtures;
use Tests\Support\Memberships;

/*
 * Sesión del autor (guard `author`) y lo que ve de su propuesta (plan 006, CA-006-37, CA-006-38 y CA-006-40).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo('2027-01-20 12:00:00');
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

it('CA-006-37: an author session does not open the organizer panel', function (): void {
    $submission = AuthorFixtures::submission();
    $organization = Organization::query()->findOrFail($submission->organization_id);
    app(AuthorSession::class)->open($submission);

    expect(Auth::guard('author')->check())->toBeTrue()
        ->and(Auth::guard('web')->check())->toBeFalse();

    foreach (["/admin/{$organization->slug}", '/admin'] as $panelPath) {
        $response = $this->get($panelPath);
        expect($response->getStatusCode())->toBe(302)
            ->and((string) $response->headers->get('Location'))->toContain('/admin/login');
    }
});

it('CA-006-37: a panel user is not granted author access to /my-submission', function (): void {
    $submission = AuthorFixtures::submission(Memberships::organization());
    $organization = Organization::query()->findOrFail($submission->organization_id);
    $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);

    $this->actingAs($admin)->get(AuthorFixtures::path($submission))
        ->assertRedirect(AuthorFixtures::path($submission, 'my-submission/request-link'));

    expect(Auth::guard('author')->check())->toBeFalse();
});

it('CA-006-38: an author session of proposal A gets 404 on another organization and on another event', function (): void {
    $a = AuthorFixtures::submission();
    $otherEventSameOrg = Event::factory()->published()->create(['organization_id' => $a->organization_id, 'slug' => 'otro-evento']);
    $other = AuthorFixtures::submission();
    $organizationA = Organization::query()->findOrFail($a->organization_id);
    app(AuthorSession::class)->open($a);

    $this->get(AuthorFixtures::path($a))->assertOk();
    $this->get(AuthorFixtures::path($other))->assertNotFound();
    $this->get("/{$organizationA->slug}/events/{$otherEventSameOrg->slug}/my-submission")->assertNotFound();
});

it('CA-006-38: the proposal comes from the session, never from a request identifier', function (): void {
    $a = AuthorFixtures::submission();
    $b = AuthorFixtures::sibling($a, 'otra@example.test', ['title' => 'Propuesta B']);
    app(AuthorSession::class)->open($a);

    $this->get(AuthorFixtures::path($a).'?submission='.$b->id.'&submission_id='.$b->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('submission.title', 'Parques vivos'));
});

it('CA-006-38: without a session, or with an expired one, the visitor is sent to request a new link', function (): void {
    $submission = AuthorFixtures::submission();
    $requestLink = AuthorFixtures::path($submission, 'my-submission/request-link');

    $this->get(AuthorFixtures::path($submission))->assertRedirect($requestLink);

    app(AuthorSession::class)->open($submission);
    $this->travel(59)->minutes();
    $this->get(AuthorFixtures::path($submission))->assertOk();

    $this->travel(2)->minutes();
    $this->get(AuthorFixtures::path($submission))->assertRedirect($requestLink);
});

it('CA-006-40: the author sees answers, participants and status, and nothing exclusive to organizers', function (): void {
    $submission = AuthorFixtures::submission(
        submission: [
            'form_snapshot' => ['fields' => [
                ['key' => 'summary', 'type' => 'long_text', 'label' => ['es' => 'Resumen'], 'config' => []],
                ['key' => 'level', 'type' => 'single_choice', 'label' => ['es' => 'Nivel'], 'config' => ['options' => [['value' => 'basic', 'label' => ['es' => 'Básico']]]]],
                ['key' => 'old', 'type' => 'short_text', 'label' => ['es' => 'Retirado'], 'config' => []],
                ['key' => 'slides', 'type' => 'file', 'label' => ['es' => 'Láminas'], 'config' => []],
            ]],
            'answers' => ['summary' => 'Un resumen', 'level' => 'basic', 'slides' => ['id-secreto-del-archivo']],
        ],
    );
    SubmissionParticipant::factory()->secondary()->create([
        'organization_id' => $submission->organization_id,
        'submission_id' => $submission->id,
        'first_name' => 'Luis',
        'last_name' => 'Gómez',
        'full_name' => 'Luis Gómez',
        'email' => 'luis@example.test',
    ]);
    AuthorFixtures::sibling($submission, 'otra@example.test', ['title' => 'Propuesta ajena secreta']);
    app(AuthorSession::class)->open($submission);

    $response = $this->get(AuthorFixtures::path($submission));

    $response->assertOk();
    $props = (array) AssertableInertia::fromTestResponse($response)->toArray()['props'];
    $data = (array) $props['submission'];
    expect($data['title'])->toBe('Parques vivos')
        ->and($data['status'])->toBe('submitted')
        ->and($data['answers'])->toBe([
            ['key' => 'summary', 'label' => 'Resumen', 'type' => 'long_text', 'value' => 'Un resumen'],
            ['key' => 'level', 'label' => 'Nivel', 'type' => 'single_choice', 'value' => 'Básico'],
            ['key' => 'slides', 'label' => 'Láminas', 'type' => 'file', 'value' => '1'],
        ])
        ->and(array_column((array) $data['participants'], 'fullName'))->toBe(['Ana Pérez', 'Luis Gómez']);

    $everything = (string) json_encode($props);
    foreach (['Propuesta ajena secreta', 'id-secreto-del-archivo', 'idempotency_key', 'payload_hash', 'organization_id', 'registered_by', 'token_hash'] as $forbidden) {
        expect($everything)->not->toContain($forbidden);
    }
});

/*
 * Plan 034: cerrar la sesión a mano y aislamiento de la sesión abierta al enviar (CA-034-15 a 17).
 */

it('CA-034-15: a session opened by submitting in organization A grants nothing on organization B', function (): void {
    $a = AuthorFixtures::submission();
    $b = AuthorFixtures::submission();
    app(AuthorSession::class)->open($a, AuthorSession::VIA_SUBMIT);

    $this->get(AuthorFixtures::path($a))->assertOk();
    $this->get(AuthorFixtures::path($b))->assertNotFound();
    $this->post(AuthorFixtures::path($b, 'my-submission/logout'))->assertNotFound();
});

it('CA-034-16: POST logout closes the author session, audits it and returns to the call page', function (): void {
    $submission = AuthorFixtures::submission();
    $organization = Organization::query()->findOrFail($submission->organization_id);
    app(AuthorSession::class)->open($submission);
    $this->get(AuthorFixtures::path($submission))->assertOk();

    $response = $this->post(AuthorFixtures::path($submission, 'my-submission/logout'));

    $response->assertStatus(303);
    expect((string) $response->headers->get('Location'))->toContain("/{$organization->slug}/events/")
        ->and(Activity::query()->where('event', 'author_session_closed')->count())->toBe(1);
    $this->get(AuthorFixtures::path($submission))->assertRedirect(AuthorFixtures::path($submission, 'my-submission/request-link'));
});

it('CA-034-16: logout answers 405 to GET and sends a visitor without session to request a link', function (): void {
    $submission = AuthorFixtures::submission();
    $requestLink = AuthorFixtures::path($submission, 'my-submission/request-link');

    $this->post(AuthorFixtures::path($submission, 'my-submission/logout'))->assertRedirect($requestLink);

    app(AuthorSession::class)->open($submission);
    $this->get(AuthorFixtures::path($submission, 'my-submission/logout'))->assertMethodNotAllowed();
    $this->get(AuthorFixtures::path($submission))->assertOk();
});

it('CA-034-17: opening by link and closing the session are audited without personal data', function (): void {
    $submission = AuthorFixtures::submission();
    $session = app(AuthorSession::class);

    $session->open($submission);
    $session->logout();

    $opened = Activity::query()->where('event', 'author_session_opened')->sole();
    $closed = Activity::query()->where('event', 'author_session_closed')->sole();
    expect($opened->properties->get('via'))->toBe('link')
        ->and($opened->properties->get('organization_id'))->toBe($submission->organization_id)
        ->and($closed->properties->get('organization_id'))->toBe($submission->organization_id)
        ->and((string) $opened->properties.(string) $closed->properties)->not->toContain('ana@example.test');
});
