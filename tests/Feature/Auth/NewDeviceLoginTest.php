<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Pages\Auth\Login;
use App\Models\KnownDevice;
use App\Models\User;
use App\Notifications\NewDeviceLoginNotification;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Login as LoginEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

function deviceUser(): User
{
    return Memberships::attach(
        User::factory()->create(['email' => 'ana@example.test', 'password' => 'correct-horse-battery']),
        Memberships::organization(),
        OrganizationRole::OrgAdmin,
    );
}

/** Dispara el evento Login como si la petición viniera de ese agente y esa IP. */
function loginFrom(User $user, string $userAgent, string $ip = '203.0.113.9'): void
{
    app()->instance('request', Request::create('/admin/login', 'POST', server: [
        'REMOTE_ADDR' => $ip,
        'HTTP_USER_AGENT' => $userAgent,
    ]));

    event(new LoginEvent('web', $user, false));
}

it('CA-001-35: a login from an unregistered device queues auth.new_device_login and registers the device', function (): void {
    Notification::fake();
    $user = deviceUser();

    loginFrom($user, CHROME_WINDOWS);

    Notification::assertSentTo($user, NewDeviceLoginNotification::class);
    $device = KnownDevice::query()->sole();
    expect($device->user_id)->toBe($user->id)
        ->and($device->fingerprint_hash)->toHaveLength(64)
        ->and($device->fingerprint_hash)->not->toContain('Chrome')
        ->and($device->fingerprint_hash)->not->toContain('203.0.113')
        ->and($device->last_seen_at)->not->toBeNull()
        ->and(new NewDeviceLoginNotification(CarbonImmutable::now(), 'x'))->toBeInstanceOf(ShouldQueue::class)
        ->and(NewDeviceLoginNotification::CATALOG_KEY)->toBe('auth.new_device_login');
});

it('CA-001-35: a login from a known device sends no notice and refreshes last_seen_at', function (): void {
    Notification::fake();
    $user = deviceUser();
    loginFrom($user, CHROME_WINDOWS);
    $firstSeen = KnownDevice::query()->sole()->last_seen_at;

    $this->travel(2)->hours();
    // Mismo navegador y misma red /24, otro host y otra versión del navegador.
    loginFrom($user, str_replace('130.0.0.0', '131.0.0.0', CHROME_WINDOWS), '203.0.113.77');

    Notification::assertSentToTimes($user, NewDeviceLoginNotification::class, 1);
    expect(KnownDevice::query()->count())->toBe(1)
        ->and(KnownDevice::query()->sole()->last_seen_at?->gt($firstSeen))->toBeTrue();
});

it('CA-001-35: another browser or another network is a new device', function (): void {
    Notification::fake();
    $user = deviceUser();

    loginFrom($user, CHROME_WINDOWS);
    loginFrom($user, SAFARI_IPHONE);
    loginFrom($user, CHROME_WINDOWS, '198.51.100.4');

    Notification::assertSentToTimes($user, NewDeviceLoginNotification::class, 3);
    expect(KnownDevice::query()->count())->toBe(3);
});

it('CA-001-35: the same device of another user is a new device for that user', function (): void {
    Notification::fake();
    $ana = deviceUser();
    $bruno = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::Organizer);

    loginFrom($ana, CHROME_WINDOWS);
    loginFrom($bruno, CHROME_WINDOWS);

    Notification::assertSentTo($bruno, NewDeviceLoginNotification::class);
    expect(KnownDevice::query()->count())->toBe(2);
});

it('CA-001-35: the database keeps one row per user and fingerprint', function (): void {
    $user = deviceUser();
    KnownDevice::query()->create(['user_id' => $user->id, 'fingerprint_hash' => str_repeat('a', 64), 'last_seen_at' => now()]);

    expect(fn () => KnownDevice::query()->create(['user_id' => $user->id, 'fingerprint_hash' => str_repeat('a', 64), 'last_seen_at' => now()]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('CA-001-35: signing in through the panel login triggers the notice', function (): void {
    Notification::fake();
    Filament::setCurrentPanel('admin');
    $user = deviceUser();

    Livewire::withHeaders(['User-Agent' => CHROME_WINDOWS])
        ->test(Login::class)
        ->fillForm(['email' => 'ana@example.test', 'password' => 'correct-horse-battery'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    Notification::assertSentTo($user, NewDeviceLoginNotification::class);
});

it('CA-001-36: the notice shows only the date and an approximate device, never secrets', function (): void {
    $user = deviceUser();
    $notification = new NewDeviceLoginNotification(CarbonImmutable::parse('2026-11-09 18:30:00'), 'Chrome / Windows');

    $mail = $notification->toMail($user);
    $rendered = (string) $mail->render();
    $content = json_encode([$mail->subject, $mail->introLines, $mail->outroLines, $mail->actionUrl, get_object_vars($notification)]);

    expect($rendered)->toContain('2026-11-09 18:30')->toContain('Chrome / Windows')
        ->and($mail->actionUrl)->toBeNull();
    foreach (['correct-horse-battery', $user->password, 'token', '203.0.113'] as $secret) {
        expect($content)->not->toContain($secret);
    }
});

it('CA-001-36: the audit record of a new device holds only the approximate device', function (): void {
    Notification::fake();
    $user = deviceUser();

    loginFrom($user, CHROME_WINDOWS);

    $entry = Activity::query()->where('event', 'new_device_login')->sole();
    expect($entry->properties->all())->toBe(['device' => 'Chrome / Windows']);
    $stored = json_encode([$entry->properties, KnownDevice::query()->sole()->toArray()]);
    foreach (['203.0.113', 'Mozilla'] as $raw) {
        expect($stored)->not->toContain($raw);
    }
});

it('CA-001-36: its texts exist in es and en', function (): void {
    foreach (['es', 'en'] as $locale) {
        foreach (['subject', 'greeting', 'line', 'when', 'device', 'ignore'] as $key) {
            expect(trans("access.new_device.{$key}", [], $locale))->not->toBe("access.new_device.{$key}");
        }
    }
});
