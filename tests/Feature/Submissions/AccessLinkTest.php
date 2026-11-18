<?php

declare(strict_types=1);

use App\Actions\Submissions\ConsumeAccessToken;
use App\Actions\Submissions\CreateAccessToken;
use App\Actions\Submissions\SendAccessLink;
use App\Enums\EventStatus;
use App\Enums\SubmissionStatus;
use App\Exceptions\Domain\AccessLinkInvalid;
use App\Jobs\SendTransactionalMail;
use App\Models\Activity;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionAccessToken;
use App\Models\SubmissionParticipant;
use App\Support\Auth\AuthorSession;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AuthorFixtures;

/*
 * Pedir, enviar y consumir el enlace de acceso del autor (plan 006, H-006-3, CA-006-25 y CA-006-30 a 36 y 39).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo('2027-01-20 12:00:00');
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return list<SendTransactionalMail> */
function accessLinkMails(): array
{
    return Queue::pushed(SendTransactionalMail::class, fn (SendTransactionalMail $job): bool => $job->notificationKey === 'submission.access_link')->values()->all();
}

/**
 * Cuerpo de la petición «pedir enlace», con un token de Turnstile distinto cada vez. Simula la respuesta
 * del servicio anti-robots; la primera simulación de cada prueba es la que vale.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function accessLinkPayload(array $data = [], bool $isHuman = true): array
{
    static $counter = 0;

    Http::fake(['*' => Http::response(['success' => $isHuman])]);

    return ['email' => 'ana@example.test', 'turnstile_token' => 'token-turnstile-'.++$counter, ...$data];
}

function accessLinkPath(Submission $submission): string
{
    return AuthorFixtures::path($submission, 'my-submission/request-link');
}

it('CA-006-30: creates an expiring token and queues the access link for each active proposal of the primary email', function (): void {
    Queue::fake();
    $submission = AuthorFixtures::submission();
    $event = Event::query()->findOrFail($submission->event_id);

    $sent = app(SendAccessLink::class)->handle($event, 'ANA@example.test ');

    $token = SubmissionAccessToken::query()->sole();
    $mails = accessLinkMails();
    expect($sent)->toBe(1)
        ->and($token->submission_id)->toBe($submission->id)
        ->and($token->expires_at->equalTo(now()->addMinutes(30)))->toBeTrue()
        ->and($mails)->toHaveCount(1)
        ->and($mails[0]->recipient->email)->toBe('ana@example.test')
        ->and($mails[0]->variables['access_url'])->toContain('/my-submission/access/')
        ->and($mails[0]->variables['expires_in_minutes'])->toBe('30');
});

it('CA-006-30: sends at most five links, one per active proposal, and skips withdrawn, secondary-only and other-event proposals', function (): void {
    Queue::fake();
    $first = AuthorFixtures::submission();
    $event = Event::query()->findOrFail($first->event_id);
    $organizationId = $first->organization_id;

    foreach (range(1, 6) as $number) {
        AuthorFixtures::sibling($first, 'ana@example.test', ['title' => "Extra {$number}"]);
    }
    $withdrawn = AuthorFixtures::sibling($first, 'ana@example.test', ['status' => SubmissionStatus::Withdrawn, 'withdrawn_at' => now()]);
    // Ana solo es participante secundaria de esta propuesta: no es la persona principal.
    $secondary = AuthorFixtures::sibling($first, 'otra@example.test');
    SubmissionParticipant::factory()->secondary()->create(['organization_id' => $organizationId, 'submission_id' => $secondary->id, 'email' => 'ana@example.test']);
    $otherEvent = Event::factory()->published()->create(['organization_id' => $organizationId]);
    $elsewhere = Submission::factory()->create(['organization_id' => $organizationId, 'event_id' => $otherEvent->id]);
    SubmissionParticipant::factory()->create(['organization_id' => $organizationId, 'submission_id' => $elsewhere->id, 'email' => 'ana@example.test']);

    $sent = app(SendAccessLink::class)->handle($event, 'ana@example.test');

    $tokenSubmissions = SubmissionAccessToken::query()->pluck('submission_id')->all();
    expect($sent)->toBe(5)
        ->and($tokenSubmissions)->toHaveCount(5)
        ->and($tokenSubmissions)->not->toContain($withdrawn->id)
        ->and($tokenSubmissions)->not->toContain($secondary->id)
        ->and($tokenSubmissions)->not->toContain($elsewhere->id)
        ->and(accessLinkMails())->toHaveCount(5);
});

it('CA-006-31: an email without proposals gets no token and no mail', function (): void {
    Queue::fake();
    $submission = AuthorFixtures::submission();
    $event = Event::query()->findOrFail($submission->event_id);

    $sent = app(SendAccessLink::class)->handle($event, 'nadie@example.test');

    expect($sent)->toBe(0)
        ->and(SubmissionAccessToken::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('CA-006-32: the response is identical whether or not the email has proposals, and only queues work', function (): void {
    Queue::fake();
    Mail::fake();
    $submission = AuthorFixtures::submission();

    $known = $this->post(accessLinkPath($submission), accessLinkPayload(['email' => 'ana@example.test']));
    $unknown = $this->post(accessLinkPath($submission), accessLinkPayload(['email' => 'nadie@example.test']));

    expect($known->getStatusCode())->toBe($unknown->getStatusCode())->toBe(303)
        ->and($known->headers->get('Location'))->toBe($unknown->headers->get('Location'))
        ->and($known->getContent())->toBe($unknown->getContent());
    expect(accessLinkMails())->toHaveCount(1);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();

    $this->get($known->headers->get('Location'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Author/RequestLink')->where('linkRequested', true));
});

it('CA-006-25: the access-link request is limited per email and answers 429 with Retry-After without telling if the email exists', function (): void {
    Queue::fake();
    $submission = AuthorFixtures::submission();

    // Un correo con propuestas y otro sin ellas se comportan igual: tres solicitudes pasan y la cuarta da 429.
    $statuses = [];
    foreach (['ana@example.test', 'nadie@example.test'] as $email) {
        foreach (range(1, 4) as $attempt) {
            $response = $this->post(accessLinkPath($submission), accessLinkPayload(['email' => $email]));
            $statuses[$email][] = $response->getStatusCode();
            if ($attempt === 4) {
                $response->assertStatus(429)->assertHeader('Retry-After')
                    ->assertInertia(fn ($page) => $page->component('Author/RequestLink')->where('tooManyRequests', true));
            }
        }
    }

    expect($statuses['ana@example.test'])->toBe([303, 303, 303, 429])
        ->and($statuses['nadie@example.test'])->toBe([303, 303, 303, 429])
        ->and(accessLinkMails())->toHaveCount(3);
});

it('CA-006-25: the access-link request is limited per IP', function (): void {
    Queue::fake();
    $submission = AuthorFixtures::submission();

    $statuses = [];
    foreach (range(1, 11) as $number) {
        $statuses[] = $this->post(accessLinkPath($submission), accessLinkPayload(['email' => "persona{$number}@example.test"]))->getStatusCode();
    }

    expect(array_slice($statuses, 0, 10))->each->toBe(303)
        ->and($statuses[10])->toBe(429);
});

it('CA-006-21: a rejected anti-robot token queues nothing', function (): void {
    Queue::fake();
    $submission = AuthorFixtures::submission();

    $this->post(accessLinkPath($submission), accessLinkPayload(isHuman: false))->assertSessionHasErrors('turnstile_token');

    Queue::assertNothingPushed();
    expect(SubmissionAccessToken::query()->count())->toBe(0);
});

it('CA-006-32: the request form validates the email and hides drafts', function (): void {
    $submission = AuthorFixtures::submission();

    $this->post(accessLinkPath($submission), accessLinkPayload(['email' => 'no-es-correo']))->assertSessionHasErrors('email');

    Event::query()->whereKey($submission->event_id)->update(['status' => EventStatus::Draft->value]);
    $this->get(AuthorFixtures::path($submission, 'my-submission/request-link'))->assertNotFound();
});

it('CA-006-33: the token has at least 128 random bits, only its SHA-256 hash is stored and compared in constant time', function (): void {
    $submission = AuthorFixtures::submission();

    $issued = app(CreateAccessToken::class)->handle($submission);
    $other = app(CreateAccessToken::class)->handle($submission);

    $stored = SubmissionAccessToken::query()->whereKey($issued->id)->sole();
    $row = (string) json_encode(SubmissionAccessToken::query()->withoutGlobalScopes()->get()->makeVisible('token_hash')->toArray());
    $audit = (string) json_encode(Activity::query()->get()->toArray());

    expect(strlen($issued->plain))->toBeGreaterThanOrEqual(43)
        ->and($issued->plain)->not->toBe($other->plain)
        ->and($stored->token_hash)->toBe(hash('sha256', $issued->plain))
        ->and($row)->not->toContain($issued->plain)
        ->and($audit)->not->toContain($issued->plain)
        ->and(file_get_contents(app_path('Actions/Submissions/ConsumeAccessToken.php')))->toContain('hash_equals(');
});

it('CA-006-34: opening the link with GET does not consume it; confirming with POST consumes it, opens the author session and the link stops working', function (): void {
    $submission = AuthorFixtures::submission();
    $token = AuthorFixtures::token($submission);
    $accessPath = AuthorFixtures::path($submission, 'my-submission/access/'.$token);

    $this->get($accessPath)->assertOk()
        ->assertInertia(fn ($page) => $page->component('Author/ConfirmAccess'));
    expect(SubmissionAccessToken::query()->sole()->consumed_at)->toBeNull();
    $this->get(AuthorFixtures::path($submission))->assertRedirect(AuthorFixtures::path($submission, 'my-submission/request-link'));

    $this->post($accessPath)->assertRedirect(AuthorFixtures::path($submission));
    expect(SubmissionAccessToken::query()->sole()->consumed_at)->not->toBeNull();

    $this->get(AuthorFixtures::path($submission))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Author/Show')->where('submission.title', 'Parques vivos'));

    $this->post($accessPath)->assertStatus(410);
});

it('CA-006-34: the session id is regenerated when the author session opens', function (): void {
    $submission = AuthorFixtures::submission();
    $session = app('session.store');
    $session->setId('a'.str_repeat('b', 39));
    $before = $session->getId();

    app(AuthorSession::class)->open($submission);

    expect($session->getId())->not->toBe($before)
        ->and(app(AuthorSession::class)->current()?->submissionId)->toBe($submission->id);
});

it('CA-006-35: of two confirmations of the same token only one opens a session', function (): void {
    $submission = AuthorFixtures::submission();
    $token = AuthorFixtures::token($submission);
    $event = Event::query()->findOrFail($submission->event_id);

    $winner = app(ConsumeAccessToken::class)->handle($event, $token);

    expect($winner->id)->toBe($submission->id)
        ->and(fn () => app(ConsumeAccessToken::class)->handle($event, $token))->toThrow(AccessLinkInvalid::class)
        ->and(SubmissionAccessToken::query()->whereNotNull('consumed_at')->count())->toBe(1);
});

/*
 * Una prueba con DOS conexiones reales no es viable aquí: `RefreshDatabase` envuelve cada prueba en una
 * transacción sin confirmar que una segunda conexión no vería, y PHP corre en un solo proceso. Se simula la
 * carrera de verdad en la base: justo después de que la Action lee el token, «otra petición» lo consume; el
 * `UPDATE ... WHERE consumed_at IS NULL` de la primera afecta cero filas y se rechaza.
 */
it('CA-006-35: a confirmation that loses the race after reading the token is rejected', function (): void {
    $submission = AuthorFixtures::submission();
    $token = AuthorFixtures::token($submission);
    $event = Event::query()->findOrFail($submission->event_id);
    $armed = true;
    DB::listen(function ($query) use (&$armed): void {
        if (! $armed || ! str_contains($query->sql, 'from "submission_access_tokens"') || ! str_contains($query->sql, 'token_hash')) {
            return;
        }
        $armed = false;
        SubmissionAccessToken::query()->update(['consumed_at' => now()]);
    });

    expect(fn () => app(ConsumeAccessToken::class)->handle($event, $token))->toThrow(AccessLinkInvalid::class);
});

it('CA-006-36: consumed, expired, altered, unknown and other-organization tokens all show the same generic message', function (): void {
    $submission = AuthorFixtures::submission();
    $path = fn (string $token): string => AuthorFixtures::path($submission, 'my-submission/access/'.$token);
    $consumed = AuthorFixtures::token($submission, 'consumido-'.str_repeat('a', 30), ['consumed_at' => now()]);
    $expired = AuthorFixtures::token($submission, 'caducado-'.str_repeat('b', 30), ['expires_at' => now()->subSecond()]);
    $valid = AuthorFixtures::token($submission);
    $altered = substr($valid, 0, -1).'X';
    $other = AuthorFixtures::submission();
    $foreign = AuthorFixtures::token($other, 'ajeno-'.str_repeat('c', 40));
    CurrentOrganization::set(Organization::query()->findOrFail($submission->organization_id));

    // Las páginas Inertia (X-Inertia) se comparan por componente y props: el `url` es lo único que cambia con el token.
    $pages = [];
    foreach ([$consumed, $expired, $altered, 'inexistente-'.str_repeat('d', 30), $foreign, str_repeat('e', 200)] as $token) {
        $response = $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->post($path($token));
        $response->assertStatus(410);
        $pages[] = json_encode([$response->json('component'), $response->json('props')]);
    }

    expect(array_unique($pages))->toHaveCount(1)
        ->and(json_decode((string) $pages[0], true)[0])->toBe('Author/AccessInvalid')
        ->and(SubmissionAccessToken::query()->whereNull('consumed_at')->where('token_hash', hash('sha256', $valid))->exists())->toBeTrue()
        ->and(SubmissionAccessToken::query()->withoutGlobalScopes()->where('token_hash', hash('sha256', $foreign))->sole()->consumed_at)->toBeNull();
});

it('CA-006-36: a valid token of another event of the same organization is rejected without being consumed', function (): void {
    $submission = AuthorFixtures::submission();
    $token = AuthorFixtures::token($submission);
    $otherEvent = Event::factory()->published()->create(['organization_id' => $submission->organization_id, 'slug' => 'otro-evento']);
    $organization = Organization::query()->findOrFail($submission->organization_id);

    $this->post("/{$organization->slug}/events/{$otherEvent->slug}/my-submission/access/{$token}")->assertStatus(410);

    expect(SubmissionAccessToken::query()->sole()->consumed_at)->toBeNull();
});

it('CA-006-39: token routes answer no-referrer and no-store, and the token never reaches the application logs', function (): void {
    $logged = [];
    EventBus::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });
    $submission = AuthorFixtures::submission();
    $token = AuthorFixtures::token($submission);
    $path = AuthorFixtures::path($submission, 'my-submission/access/'.$token);

    $confirmation = $this->get($path);
    $invalid = $this->post(AuthorFixtures::path($submission, 'my-submission/access/'.str_repeat('z', 40)));
    $consume = $this->post($path);
    $show = $this->get(AuthorFixtures::path($submission));

    foreach ([$confirmation, $invalid, $consume, $show] as $response) {
        expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
            ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    }
    expect(implode("\n", $logged))->not->toContain($token);
});
