<?php

declare(strict_types=1);

use App\Actions\Notifications\SendNotification;
use App\Enums\MailDispatchStatus;
use App\Jobs\SendTransactionalMail;
use App\Mail\OutgoingMailable;
use App\Models\MailDispatch;
use App\Models\Organization;
use App\Support\Notifications\MailRecipient;
use App\Support\Notifications\OutgoingMail;
use App\Support\Notifications\TransactionalMailer;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * Envío por cola, idempotente y con límite por destinatario (plan 008, H-008-6, ADR-0009).
 * `SendNotification` es la única Action que fija a quién se envía.
 */

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
    Carbon::setTestNow();
});

/** @return array<string, string> */
function receivedContext(string $reference = 'PR-1'): array
{
    return [
        'author_name' => 'Ana Pérez',
        'submission_title' => 'Parques vivos',
        'submission_reference' => $reference,
        'event_name' => 'Congreso Parques 2027',
    ];
}

/** @param  array<string, string>  $variables */
function notify(
    Organization $organization,
    string $key = 'submission.received',
    string $email = 'ana@example.test',
    string $factId = 'fact-1',
    ?array $variables = null,
    ?string $nonce = null,
): MailDispatch {
    return app(SendNotification::class)->handle(
        key: $key,
        recipient: new MailRecipient($email, 'Ana Pérez', 'es'),
        variables: $variables ?? receivedContext(),
        factId: $factId,
        organization: $organization,
        nonce: $nonce,
    );
}

function runQueuedJob(int $index = 0): void
{
    /** @var SendTransactionalMail $job */
    $job = Queue::pushed(SendTransactionalMail::class)->values()[$index];

    app()->call([$job, 'handle']);
}

it('CA-008-23: queues the send in a job and the web process does not wait for the provider', function () {
    Queue::fake();
    Mail::fake();
    $organization = Organization::factory()->create();

    $dispatch = notify($organization);

    Queue::assertPushed(SendTransactionalMail::class, 1);
    Mail::assertNothingSent();
    expect($dispatch->status)->toBe(MailDispatchStatus::Queued);
});

it('CA-008-23: the queued job sends the rendered mail once it runs', function () {
    Queue::fake();
    Mail::fake();
    $organization = Organization::factory()->create();
    $dispatch = notify($organization);

    runQueuedJob();

    Mail::assertSent(OutgoingMailable::class, fn (OutgoingMailable $mail): bool => $mail->hasTo('ana@example.test')
        && str_contains((string) $mail->subject, 'Congreso Parques 2027'));
    $fresh = MailDispatch::query()->withoutGlobalScopes()->findOrFail($dispatch->id);
    expect($fresh->status)->toBe(MailDispatchStatus::Sent);
    expect($fresh->sent_at)->not->toBeNull();
});

it('CA-008-24: retries up to 5 times with an increasing wait', function () {
    Queue::fake();
    $organization = Organization::factory()->create();
    notify($organization);

    /** @var SendTransactionalMail $job */
    $job = Queue::pushed(SendTransactionalMail::class)->first();
    $backoff = $job->backoff();

    expect($job->tries)->toBe(5);
    expect(count($backoff))->toBe(4);
    $sorted = $backoff;
    sort($sorted);
    expect($backoff)->toBe($sorted);
    expect(count(array_unique($backoff)))->toBe(count($backoff));
});

it('CA-008-24: a provider failure propagates and exhausted retries leave the dispatch visibly failed', function () {
    Queue::fake();
    $organization = Organization::factory()->create();
    $dispatch = notify($organization);

    app()->bind(TransactionalMailer::class, fn () => new class implements TransactionalMailer
    {
        public function send(OutgoingMail $mail): void
        {
            throw new RuntimeException('proveedor caido');
        }
    });

    /** @var SendTransactionalMail $job */
    $job = Queue::pushed(SendTransactionalMail::class)->first();

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);

    $job->failed(new RuntimeException('proveedor caido'));

    $fresh = MailDispatch::query()->withoutGlobalScopes()->findOrFail($dispatch->id);
    expect($fresh->status)->toBe(MailDispatchStatus::Failed);
    expect($fresh->attempts)->toBeGreaterThanOrEqual(1);
});

it('CA-008-25: the same business fact processed twice sends a single mail', function () {
    Queue::fake();
    Mail::fake();
    $organization = Organization::factory()->create();

    $first = notify($organization, factId: 'submission-9');
    $second = notify($organization, factId: 'submission-9');

    expect($second->id)->toBe($first->id);
    Queue::assertPushed(SendTransactionalMail::class, 1);
    expect(MailDispatch::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('CA-008-25: a queue retry of a job that already sent does not send again', function () {
    Queue::fake();
    Mail::fake();
    $organization = Organization::factory()->create();
    notify($organization, factId: 'submission-9');

    runQueuedJob();
    runQueuedJob();

    Mail::assertSent(OutgoingMailable::class, 1);
});

it('CA-008-26: two access-link requests send two different mails, each safe from duplicates', function () {
    Queue::fake();
    Mail::fake();
    $organization = Organization::factory()->create();
    $context = [
        'author_name' => 'Ana',
        'event_name' => 'Congreso',
        'access_url' => 'https://example.test/a',
        'expires_in_minutes' => '30',
    ];

    notify($organization, 'submission.access_link', factId: 'submission-9', variables: $context, nonce: 'req-1');
    notify($organization, 'submission.access_link', factId: 'submission-9', variables: $context, nonce: 'req-2');
    notify($organization, 'submission.access_link', factId: 'submission-9', variables: $context, nonce: 'req-2');

    Queue::assertPushed(SendTransactionalMail::class, 2);
    expect(MailDispatch::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('CA-008-28: the sixth mail to the same address within an hour is throttled and never queued', function () {
    Queue::fake();
    $organization = Organization::factory()->create();

    foreach (range(1, 5) as $n) {
        expect(notify($organization, factId: "fact-{$n}")->status)->toBe(MailDispatchStatus::Queued);
    }

    $sixth = notify($organization, factId: 'fact-6');

    expect($sixth->status)->toBe(MailDispatchStatus::Throttled);
    Queue::assertPushed(SendTransactionalMail::class, 5);
});

it('CA-008-28: another address is not affected by the limit', function () {
    Queue::fake();
    $organization = Organization::factory()->create();

    foreach (range(1, 5) as $n) {
        notify($organization, factId: "fact-{$n}");
    }

    expect(notify($organization, email: 'otra@example.test', factId: 'fact-7')->status)->toBe(MailDispatchStatus::Queued);
});

it('CA-008-28: the limit expires after an hour', function () {
    Queue::fake();
    $organization = Organization::factory()->create();
    Carbon::setTestNow('2026-11-12 10:00:00');

    foreach (range(1, 5) as $n) {
        notify($organization, factId: "fact-{$n}");
    }

    Carbon::setTestNow('2026-11-12 11:01:00');

    expect(notify($organization, factId: 'fact-6')->status)->toBe(MailDispatchStatus::Queued);
});

it('CA-008-28: the plain email address is never stored in mail_dispatches', function () {
    Queue::fake();
    $organization = Organization::factory()->create();
    notify($organization, email: 'ana@example.test');

    $rows = DB::table('mail_dispatches')->get()->toJson();

    expect($rows)->not->toContain('ana@example.test');
});

it('CA-008-28: the limit is per organization', function () {
    Queue::fake();
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    foreach (range(1, 5) as $n) {
        notify($organizationA, factId: "fact-{$n}");
    }

    expect(notify($organizationB, factId: 'fact-1')->status)->toBe(MailDispatchStatus::Queued);
});

it('CA-008-11: only the system decides the recipient; variables and values cannot add copies', function () {
    Queue::fake();
    Mail::fake();
    $organization = Organization::factory()->create();

    notify($organization, variables: receivedContext() + [
        'to' => 'atacante@example.test',
        'cc' => 'atacante@example.test',
        'bcc' => 'atacante@example.test',
    ]);
    runQueuedJob();

    Mail::assertSent(OutgoingMailable::class, function (OutgoingMailable $mail): bool {
        return $mail->hasTo('ana@example.test')
            && ! $mail->hasTo('atacante@example.test')
            && ! $mail->hasCc('atacante@example.test')
            && ! $mail->hasBcc('atacante@example.test')
            && count($mail->to) === 1
            && $mail->cc === []
            && $mail->bcc === [];
    });
});

it('CA-008-11: a value carrying a smuggled Bcc header adds no recipient', function () {
    Queue::fake();
    Mail::fake();
    $organization = Organization::factory()->create();

    notify($organization, variables: ['event_name' => "Congreso\r\nBcc: atacante@example.test"] + receivedContext());
    runQueuedJob();

    Mail::assertSent(OutgoingMailable::class, fn (OutgoingMailable $mail): bool => ! $mail->hasBcc('atacante@example.test') && $mail->bcc === []);
});

it('CA-008-11: a recipient name with line breaks cannot inject headers', function () {
    Queue::fake();
    Mail::fake();
    $organization = Organization::factory()->create();

    app(SendNotification::class)->handle(
        key: 'submission.received',
        recipient: new MailRecipient('ana@example.test', "Ana\r\nBcc: atacante@example.test", 'es'),
        variables: receivedContext(),
        factId: 'fact-1',
        organization: $organization,
    );
    runQueuedJob();

    Mail::assertSent(OutgoingMailable::class, fn (OutgoingMailable $mail): bool => $mail->bcc === [] && count($mail->to) === 1);
});

it('rejects a notification key that is not in the catalog', function () {
    $organization = Organization::factory()->create();

    notify($organization, key: 'made.up_key');
})->throws(InvalidArgumentException::class);

it('runs the job inside the organization of the dispatch and leaves no context behind', function () {
    Queue::fake();
    Mail::fake();
    $organization = Organization::factory()->create();
    notify($organization);
    CurrentOrganization::forget();

    runQueuedJob();

    expect(CurrentOrganization::has())->toBeFalse();
    Mail::assertSent(OutgoingMailable::class, 1);
});

it('an organization cannot see the dispatches of another', function () {
    Queue::fake();
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    notify($organizationA);

    CurrentOrganization::set($organizationB);

    expect(MailDispatch::query()->count())->toBe(0);
});
