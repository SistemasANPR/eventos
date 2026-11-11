<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Support\Auth\AccessThrottle;
use App\Support\Auth\PortalDestination;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as FilamentLogin;
use Filament\Facades\Filament;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Inicio de sesión del panel con el límite del plan 001 (DA-6): 5 intentos por
 * 15 minutos por correo e IP, con 429 y auditoría.
 *
 * La limitación de Filament cuenta por IP y minuto y responde con una
 * notificación, sin código HTTP ni auditoría; se conserva como segunda capa
 * (más estricta por IP) y esta página añade la del plan.
 *
 * Es el acceso único (plan 017): acepta a quien pueda entrar a **algún** panel
 * (organizaciones o plataforma) con el mismo mensaje de error ante cualquier
 * negativa, y la respuesta de éxito lo lleva a su portal (`LoginResponse`).
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

    /**
     * Una persona ya autenticada va a su portal. Si su sesión ya no da acceso a
     * ningún panel (por ejemplo, su organización fue suspendida), se cierra y se
     * muestra el formulario: redirigirla de vuelta aquí produciría un bucle.
     */
    #[Unexposed]
    public function mount(): void
    {
        $user = Filament::auth()->user();

        if ($user instanceof User && $user->canAccessAnyPanel()) {
            $this->redirect(PortalDestination::home($user));

            return;
        }

        if ($user !== null) {
            Filament::auth()->logout();
            session()->invalidate();
            session()->regenerateToken();
        }

        parent::mount();
    }

    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        return $user instanceof User ? $user->canAccessAnyPanel() : parent::isUserAllowedToAccessPanel($user);
    }
}
