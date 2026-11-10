<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Puente entre las Actions y los formularios de Filament.
 *
 * Las Actions validan con claves sin prefijo (`name.es`, `slug`) y Filament
 * espera los errores bajo el estado del formulario (`data.name.es`): sin esta
 * conversión, el error existe pero no aparece junto a su campo.
 */
final class ActionErrors
{
    /** Convierte la excepción de una Action en una que Filament pinta en cada campo. */
    public static function forForm(ValidationException $exception, string $statePath = 'data'): ValidationException
    {
        $prefixed = [];

        foreach ($exception->errors() as $field => $messages) {
            $prefixed["{$statePath}.{$field}"] = $messages;
        }

        return ValidationException::withMessages($prefixed);
    }

    /** Texto plano con todos los mensajes, para una notificación. */
    public static function summary(ValidationException $exception): string
    {
        return collect($exception->errors())->flatten()->implode(' ');
    }

    /** Quien ejecuta la acción en el panel. */
    public static function actor(): User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : throw new RuntimeException('No hay un usuario autenticado en el panel.');
    }
}
