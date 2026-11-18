<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;

/** Audita el restablecimiento de contraseña (plan 001 §5); nunca guarda la contraseña. */
final class LogPasswordReset
{
    public function handle(PasswordReset $event): void
    {
        activity('auth')
            ->causedBy($event->user instanceof Model ? $event->user : null)
            ->event('password_reset')
            ->withProperties(['ip' => (string) request()->ip()])
            ->log('password_reset');
    }
}
