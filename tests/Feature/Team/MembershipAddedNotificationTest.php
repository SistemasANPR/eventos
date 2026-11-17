<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\MembershipAddedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

it('team.membership_added: is queued on the mail channel', function (): void {
    $notification = new MembershipAddedNotification('Organización de prueba');

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->via(new User))->toBe(['mail']);
});

it('team.membership_added: names the organization and links only to the sign-in page', function (): void {
    $mail = (new MembershipAddedNotification('Organización de prueba'))->toMail(new User);

    expect($mail->introLines)->not->toBeEmpty()
        ->and(implode(' ', $mail->introLines))->toContain('Organización de prueba')
        ->and($mail->actionUrl)->toBe(route('filament.admin.auth.login'));
});

it('team.membership_added: its texts exist in es and en', function (): void {
    foreach (['es', 'en'] as $locale) {
        foreach (['subject', 'greeting', 'line', 'action'] as $key) {
            expect(trans("team.membership_added.{$key}", ['organization' => 'X'], $locale))->not->toBe("team.membership_added.{$key}");
        }
    }
});

it('team texts of the team management screen exist in es and en', function (): void {
    $keys = [
        'roles.org_admin', 'roles.organizer', 'roles.reviewer', 'status.active', 'status.deactivated',
        'invite.sent', 'invite.throttled', 'invite.already_member', 'deactivate.done', 'change_role.done',
        'errors.last_admin', 'errors.not_active', 'resource.label', 'resource.plural',
    ];

    foreach (['es', 'en'] as $locale) {
        foreach ($keys as $key) {
            expect(trans("team.{$key}", [], $locale))->not->toBe("team.{$key}");
        }
    }
});
