<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\KnownDevice;
use App\Models\User;
use App\Notifications\NewDeviceLoginNotification;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

it('CA-001-35: a login refused for lack of panel access registers no device and sends no notice', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    event(new Login('web', $user, false));

    Notification::assertNothingSent();
    expect(KnownDevice::query()->count())->toBe(0);
});

it('CA-001-35: a user with an active membership still gets the notice on a new device', function (): void {
    Notification::fake();
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);

    event(new Login('web', $user, false));

    Notification::assertSentTo($user, NewDeviceLoginNotification::class);
    expect(KnownDevice::query()->count())->toBe(1);
});
