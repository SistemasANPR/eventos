<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Models\KnownDevice;
use App\Models\User;
use App\Notifications\NewDeviceLoginNotification;
use App\Support\Auth\DeviceFingerprint;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * Al iniciar sesión, registra el dispositivo y avisa si no lo conocíamos
 * (CA-001-35). `createOrFirst` se apoya en la clave única (usuario, huella):
 * dos inicios simultáneos desde el mismo dispositivo nuevo no fallan ni envían
 * el aviso dos veces. Se avisa también en el primer inicio de sesión de un
 * usuario (opción estricta: no hay dispositivos previos que comparar).
 */
final class RegisterKnownDevice
{
    public function __construct(private readonly Request $request) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        // `Login` se emite al validar la contraseña, antes de que Filament compruebe el
        // acceso al panel: sin acceso no se registra el dispositivo ni se avisa (no se
        // revela que la contraseña era válida).
        if (! $user instanceof User || ! $user->canAccessAnyPanel()) {
            return;
        }

        $fingerprint = DeviceFingerprint::fromRequest($this->request);
        $now = CarbonImmutable::now();

        $device = KnownDevice::query()->createOrFirst(
            ['user_id' => $user->id, 'fingerprint_hash' => $fingerprint->hash],
            ['last_seen_at' => $now],
        );

        if (! $device->wasRecentlyCreated) {
            $device->update(['last_seen_at' => $now]);

            return;
        }

        activity('auth')
            ->causedBy($user)
            ->event('new_device_login')
            ->withProperties(['device' => $fingerprint->label])
            ->log('new_device_login');

        $user->notify(new NewDeviceLoginNotification($now, $fingerprint->label));
    }
}
