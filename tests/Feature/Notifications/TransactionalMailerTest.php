<?php

declare(strict_types=1);

use App\Actions\Notifications\SendNotification;
use App\Jobs\SendTransactionalMail;
use App\Mail\OutgoingMailable;
use App\Models\Organization;
use App\Support\Notifications\Mailers\ResendMailer;
use App\Support\Notifications\Mailers\SmtpMailer;
use App\Support\Notifications\MailRecipient;
use App\Support\Notifications\TransactionalMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * Proveedor intercambiable (plan 008, H-008-6, ADR-0009 decisión 1): cambiar de Resend
 * a otro es un cambio de configuración; ni los módulos ni las plantillas se tocan.
 */

uses(RefreshDatabase::class);

it('CA-008-27: the configured driver picks the adapter', function (string $driver, string $adapter) {
    config(['notifications.driver' => $driver]);

    expect(app(TransactionalMailer::class))->toBeInstanceOf($adapter);
})->with([
    'resend' => ['resend', ResendMailer::class],
    'smtp' => ['smtp', SmtpMailer::class],
]);

it('CA-008-27: the same notification is sent unchanged with either adapter', function (string $driver, string $laravelMailer) {
    config(['notifications.driver' => $driver]);
    Queue::fake();
    Mail::fake();
    $organization = Organization::factory()->create();

    app(SendNotification::class)->handle(
        key: 'submission.received',
        recipient: new MailRecipient('ana@example.test', 'Ana Pérez', 'en'),
        variables: [
            'author_name' => 'Ana',
            'submission_title' => 'Living parks',
            'submission_reference' => 'PR-1',
            'event_name' => 'Parks Congress',
        ],
        factId: 'fact-1',
        organization: $organization,
    );
    app()->call([Queue::pushed(SendTransactionalMail::class)->first(), 'handle']);

    Mail::assertSent(OutgoingMailable::class, fn (OutgoingMailable $mail): bool => $mail->mailer === $laravelMailer
        && $mail->hasTo('ana@example.test')
        && str_contains((string) $mail->subject, 'Parks Congress'));
})->with([
    'resend' => ['resend', 'resend'],
    'smtp' => ['smtp', 'smtp'],
]);

it('CA-008-27: an unknown driver fails loudly instead of falling back', function () {
    config(['notifications.driver' => 'carrier-pigeon']);

    app(TransactionalMailer::class);
})->throws(InvalidArgumentException::class);
