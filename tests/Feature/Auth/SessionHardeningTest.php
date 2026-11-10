<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('CA-001-25: the session cookie is Secure, HttpOnly and SameSite=Lax', function (): void {
    $response = $this->get('/admin/login');

    $cookie = null;
    foreach ($response->headers->getCookies() as $candidate) {
        if ($candidate->getName() === config('session.cookie')) {
            $cookie = $candidate;
        }
    }

    expect($cookie)->not->toBeNull()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');
});

it('CA-001-33: the session lifetime is 120 minutes of inactivity', function (): void {
    expect(config('session.lifetime'))->toBe(120)
        ->and(config('session.expire_on_close'))->toBeFalse();
});

it('CA-001-33: a session idle for more than 120 minutes is no longer readable', function (): void {
    // Driver de base de datos como en producción; el manejador aplica la caducidad configurada.
    config(['session.driver' => 'database']);
    app('session')->forgetDrivers();
    $handler = app('session')->driver()->getHandler();

    $handler->write('idle-session-id', 'payload-with-login');

    $this->travel(119)->minutes();
    expect($handler->read('idle-session-id'))->toBe('payload-with-login');

    // La lectura no renueva la actividad: 121 minutos después del último guardado caduca.
    $this->travel(121)->minutes();
    expect($handler->read('idle-session-id'))->toBe('');
});

it('CA-001-34: /register answers 404 for GET and POST and creates no user', function (): void {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['name' => 'Eve', 'email' => 'eve@example.test', 'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password'])
        ->assertNotFound();

    expect(User::query()->count())->toBe(0);
});

it('CA-001-34: the starter-kit login route redirects to the Filament panel login', function (): void {
    $this->get('/login')->assertRedirect(route('filament.admin.auth.login'));

    expect(route('login'))->toEndWith('/login');
});
