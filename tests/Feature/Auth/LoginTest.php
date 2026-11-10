<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

const LOGIN_PASSWORD = 'correct-horse-battery';

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

function organizerWithPassword(string $email = 'ana@example.test'): User
{
    return Memberships::attach(
        User::factory()->create(['email' => $email, 'password' => LOGIN_PASSWORD]),
        Memberships::organization(),
        OrganizationRole::OrgAdmin,
    );
}

/** @return Testable<Component> */
function attemptLogin(string $email, string $password): Testable
{
    return Livewire::test(Login::class)
        ->fillForm(['email' => $email, 'password' => $password])
        ->call('authenticate');
}

it('CA-001-25: logging in regenerates the session id', function (): void {
    organizerWithPassword();
    $before = session()->getId();

    attemptLogin('ana@example.test', LOGIN_PASSWORD)->assertHasNoFormErrors();

    expect(auth()->check())->toBeTrue()
        ->and(session()->getId())->not->toBe($before);
});

it('CA-001-26: wrong password and unknown email give the same message', function (): void {
    organizerWithPassword();

    $wrongPassword = attemptLogin('ana@example.test', 'not-the-password');
    $unknownEmail = attemptLogin('nobody@example.test', 'not-the-password');

    $wrongPassword->assertHasFormErrors(['email']);
    $unknownEmail->assertHasFormErrors(['email']);
    expect($unknownEmail->errors()->get('data.email'))->toBe($wrongPassword->errors()->get('data.email'));
});

it('CA-001-26: a user without panel access gets the same message as a wrong password', function (): void {
    User::factory()->create(['email' => 'loner@example.test', 'password' => LOGIN_PASSWORD]);
    organizerWithPassword();

    $noAccess = attemptLogin('loner@example.test', LOGIN_PASSWORD);
    $wrongPassword = attemptLogin('ana@example.test', 'not-the-password');

    expect($noAccess->errors()->get('data.email'))->toBe($wrongPassword->errors()->get('data.email'));
});

it('CA-001-27: the sixth attempt in 15 minutes for the same email and IP answers 429 and is audited', function (): void {
    organizerWithPassword();

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        attemptLogin('Ana@Example.test', 'wrong-password')->assertHasFormErrors(['email']);
    }

    attemptLogin('ana@example.test', LOGIN_PASSWORD)->assertStatus(429);
    expect(auth()->check())->toBeFalse();

    $entry = Activity::query()->where('event', 'login_throttled')->sole();
    expect($entry->log_name)->toBe('auth')
        ->and($entry->properties->get('ip'))->not->toBeNull()
        ->and($entry->properties->get('email_hash'))->toBe(hash('sha256', 'ana@example.test'))
        ->and(json_encode($entry->properties))->not->toContain('ana@example.test')
        ->and(json_encode($entry->properties))->not->toContain(LOGIN_PASSWORD);
});

it('CA-001-27: the limit is lifted once the 15 minute window passes', function (): void {
    organizerWithPassword();

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        attemptLogin('ana@example.test', 'wrong-password');
    }
    attemptLogin('ana@example.test', LOGIN_PASSWORD)->assertStatus(429);

    $this->travel(16)->minutes();

    attemptLogin('ana@example.test', LOGIN_PASSWORD)->assertHasNoFormErrors();
    expect(auth()->check())->toBeTrue();
});
