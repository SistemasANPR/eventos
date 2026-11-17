<?php

declare(strict_types=1);

use App\Actions\Notifications\SendNotification;
use App\Actions\Notifications\SetNotificationEnabled;
use App\Actions\Notifications\UpdateMailTemplate;
use App\Enums\MailDispatchStatus;
use App\Enums\OrganizationRole;
use App\Jobs\SendTransactionalMail;
use App\Mail\OutgoingMailable;
use App\Models\Event;
use App\Models\MailDispatch;
use App\Models\MailTemplate;
use App\Models\NotificationSetting;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\MailRecipient;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\Memberships;

/*
 * El envío usa la plantilla de la organización, respeta lo que desactivó y nunca apaga
 * un correo crítico (plan 008, H-008-4 y H-008-5).
 */

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array<string, string> */
function organizerNoticeVariables(): array
{
    return [
        'author_name' => 'Ana Pérez',
        'submission_title' => 'Parques vivos',
        'event_name' => 'Congreso',
        'panel_url' => 'https://panel.example.test/p/1',
    ];
}

function sendNotice(Organization $organization, string $key = 'submission.new_for_organizers', string $factId = 'fact-1', ?string $eventId = null): MailDispatch
{
    return app(SendNotification::class)->handle(
        key: $key,
        recipient: new MailRecipient('equipo@example.test', 'Equipo', 'es'),
        variables: $key === 'submission.received'
            ? ['author_name' => 'Ana', 'submission_title' => 'T', 'submission_reference' => 'PR-1', 'event_name' => 'Congreso']
            : organizerNoticeVariables(),
        factId: $factId,
        organization: $organization,
        eventId: $eventId,
    );
}

function runPushedJob(): void
{
    app()->call([Queue::pushed(SendTransactionalMail::class)->first(), 'handle']);
}

function templateAdmin(Organization $organization): User
{
    return Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
}

it('CA-008-15: the mail uses the organization template; another organization keeps the default', function (): void {
    Queue::fake();
    Mail::fake();
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    app(UpdateMailTemplate::class)->handle(templateAdmin($organizationA), $organizationA, 'submission.new_for_organizers', 'es', [
        'subject' => 'Llegó algo nuevo: {{ submission_title }}',
        'body' => 'Revisa {{ submission_title }} en {{ panel_url }}',
        'from_name' => 'Congreso Parques',
        'reply_to' => 'contacto@example.test',
    ]);

    sendNotice($organizationA);
    runPushedJob();
    Queue::fake();
    sendNotice($organizationB, factId: 'fact-b');
    runPushedJob();

    Mail::assertSent(OutgoingMailable::class, fn (OutgoingMailable $mail): bool => $mail->subject === 'Llegó algo nuevo: Parques vivos'
        && $mail->hasReplyTo('contacto@example.test')
        && $mail->from !== []
        && $mail->from[0]['name'] === 'Congreso Parques');
    Mail::assertSent(OutgoingMailable::class, fn (OutgoingMailable $mail): bool => str_contains((string) $mail->subject, 'Nueva propuesta')
        && ! $mail->hasReplyTo('contacto@example.test'));
});

it('CA-008-15: the sender address stays the verified one; only the display name changes', function (): void {
    Queue::fake();
    Mail::fake();
    config(['mail.from.address' => 'eventos@verificado.example.test']);
    $organization = Memberships::organization();
    app(UpdateMailTemplate::class)->handle(templateAdmin($organization), $organization, 'submission.new_for_organizers', 'es', [
        'subject' => 'Aviso', 'body' => 'Hola {{ author_name }}', 'from_name' => 'Congreso', 'reply_to' => '',
    ]);

    sendNotice($organization);
    runPushedJob();

    Mail::assertSent(OutgoingMailable::class, fn (OutgoingMailable $mail): bool => $mail->from[0]['address'] === 'eventos@verificado.example.test');
});

it('CA-008-20: a send for an event uses the event template over the organization one', function (): void {
    Queue::fake();
    Mail::fake();
    $organization = Memberships::organization();
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $base = ['organization_id' => $organization->id, 'notification_key' => 'submission.new_for_organizers', 'locale' => 'es', 'body' => 'x'];
    MailTemplate::factory()->create($base + ['subject' => 'De la organización']);
    MailTemplate::factory()->create($base + ['event_id' => $event->id, 'subject' => 'Del evento']);

    sendNotice($organization, eventId: $event->id);
    runPushedJob();

    Mail::assertSent(OutgoingMailable::class, fn (OutgoingMailable $mail): bool => $mail->subject === 'Del evento');
});

it('CA-008-21: a disabled non-critical notification is not sent and the fact is recorded', function (): void {
    Queue::fake();
    Mail::fake();
    $organization = Memberships::organization();
    app(SetNotificationEnabled::class)->handle(templateAdmin($organization), $organization, 'submission.new_for_organizers', false);

    $dispatch = sendNotice($organization);

    expect($dispatch->status)->toBe(MailDispatchStatus::Disabled);
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
});

it('CA-008-21: enabling it again sends the next one', function (): void {
    Queue::fake();
    Mail::fake();
    $organization = Memberships::organization();
    $admin = templateAdmin($organization);
    $toggle = app(SetNotificationEnabled::class);
    $toggle->handle($admin, $organization, 'submission.new_for_organizers', false);
    sendNotice($organization, factId: 'fact-1');
    $toggle->handle($admin, $organization, 'submission.new_for_organizers', true);

    $dispatch = sendNotice($organization, factId: 'fact-2');

    expect($dispatch->status)->toBe(MailDispatchStatus::Queued);
    Queue::assertPushed(SendTransactionalMail::class, 1);
});

it('CA-008-21: disabling in one organization does not silence another', function (): void {
    Queue::fake();
    $organizationA = Memberships::organization();
    $organizationB = Memberships::organization();
    app(SetNotificationEnabled::class)->handle(templateAdmin($organizationA), $organizationA, 'submission.new_for_organizers', false);

    expect(sendNotice($organizationA)->status)->toBe(MailDispatchStatus::Disabled);
    expect(sendNotice($organizationB, factId: 'fact-b')->status)->toBe(MailDispatchStatus::Queued);
});

it('CA-008-22: a critical notification is sent even if a disabled row exists for it', function (): void {
    Queue::fake();
    Mail::fake();
    $organization = Memberships::organization();
    NotificationSetting::factory()->create([
        'organization_id' => $organization->id,
        'notification_key' => 'submission.received',
        'is_enabled' => false,
    ]);

    $dispatch = sendNotice($organization, 'submission.received');
    runPushedJob();

    expect($dispatch->status)->toBe(MailDispatchStatus::Queued);
    Mail::assertSent(OutgoingMailable::class, 1);
});

it('a template that stops being valid at send time fails loudly instead of sending a broken mail', function (): void {
    Queue::fake();
    Mail::fake();
    $organization = Memberships::organization();
    MailTemplate::factory()->create([
        'organization_id' => $organization->id,
        'notification_key' => 'submission.new_for_organizers',
        'locale' => 'es',
        'subject' => 'Hola',
        'body' => 'Dato secreto {{ app_key }}',
    ]);

    sendNotice($organization);

    expect(fn () => runPushedJob())->toThrow(ValidationException::class);
    Mail::assertNothingSent();
});
