<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Memberships;

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

it('CA-001-33: over real HTTP, an authenticated session idle for 121 minutes is sent to the login', function (): void {
    // Sesión real en base de datos (como en producción, serialización JSON): sin estado de autenticación en memoria.
    config(['session.driver' => 'database']);
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');

    $user = Memberships::attach(User::factory()->create(), Memberships::organization(), OrganizationRole::OrgAdmin);
    $sessionId = Str::random(40);
    $cookieName = (string) config('session.cookie');
    $payload = [
        '_token' => Str::random(40),
        Auth::guard('web')->getName() => $user->getKey(),
        'password_hash_web' => $user->getAuthPassword(),
    ];
    DB::table('sessions')->insert([
        'id' => $sessionId,
        'user_id' => $user->getKey(),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Symfony',
        'payload' => base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
        'last_activity' => now()->getTimestamp(),
    ]);
    $encryptedCookie = encrypt(CookieValuePrefix::create($cookieName, app('encrypter')->getKey()).$sessionId, false);
    $login = route('filament.admin.auth.login');

    // Se simula un proceso nuevo en cada petición: ni usuario ni sesión en memoria.
    $freshRequest = function () use ($encryptedCookie, $cookieName) {
        Auth::forgetGuards();
        app('session')->forgetDrivers();
        // `session.store` es un singleton resuelto con el driver `array` de la prueba.
        app()->forgetInstance('session.store');

        return $this->withUnencryptedCookie($cookieName, $encryptedCookie)->get('/admin');
    };

    // Control: con la sesión vigente (119 minutos), /admin no redirige al inicio de sesión.
    $this->travel(119)->minutes();
    $control = $freshRequest();
    expect($control->headers->get('Location'))->not->toBe($login)->toContain('/admin/');

    // Esa petición renovó la actividad; 121 minutos después de ella la sesión caducó.
    $this->travel(121)->minutes();
    $freshRequest()->assertRedirect($login);
});
