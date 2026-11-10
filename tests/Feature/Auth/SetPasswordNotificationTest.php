<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\SetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

it('team.set_password: is queued on the mail channel', function (): void {
    $notification = new SetPasswordNotification;

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->via(new User))->toBe(['mail']);
});

it('team.set_password: its texts exist in es and en', function (): void {
    foreach (['es', 'en'] as $locale) {
        foreach (['subject', 'greeting', 'line', 'action'] as $key) {
            expect(trans("team.set_password.{$key}", [], $locale))->not->toBe("team.set_password.{$key}");
        }
    }
});
