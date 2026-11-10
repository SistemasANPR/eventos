<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Support\Auth\AccessThrottle;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as FilamentLogin;
use Filament\Support\Components\Attributes\Exposed;

/**
 * Inicio de sesión del panel con el límite del plan 001 (DA-6): 5 intentos por
 * 15 minutos por correo e IP, con 429 y auditoría.
 *
 * La limitación de Filament cuenta por IP y minuto y responde con una
 * notificación, sin código HTTP ni auditoría; se conserva como segunda capa
 * (más estricta por IP) y esta página añade la del plan.
 */
class Login extends FilamentLogin
{
    #[Exposed]
    public function authenticate(): ?LoginResponse
    {
        $throttle = app(AccessThrottle::class);
        $email = is_string($this->data['email'] ?? null) ? $this->data['email'] : '';
        $ip = (string) request()->ip();
        $bucket = 'login:'.hash('sha256', AccessThrottle::emailHash($email).'|'.$ip);

        $throttle->hitOrFail('login_throttled', [$bucket], ['ip' => $ip, 'email_hash' => AccessThrottle::emailHash($email)]);

        $response = parent::authenticate();

        if ($response !== null) {
            $throttle->clear([$bucket]);
        }

        return $response;
    }
}
