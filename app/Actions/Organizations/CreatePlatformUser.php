<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Models\User;
use App\Notifications\SetPasswordNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Crea un usuario de plataforma (`is_platform_admin`, plan 001, DA-8 y CA-001-16).
 *
 * Es la única vía de crear esa marca: no es asignable en masa, y no se expone
 * a peticiones HTTP (solo la invoca el comando de Artisan). Nunca ascende a un
 * usuario existente: un correo ya registrado se rechaza.
 *
 * @throws ValidationException si el correo es inválido o ya está registrado
 */
final class CreatePlatformUser
{
    public function handle(string $email, string $name): User
    {
        // Postgres distingue mayúsculas y el restablecimiento busca en minúsculas.
        $email = mb_strtolower(trim($email));

        Validator::make(
            ['email' => $email, 'name' => $name],
            [
                'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
                'name' => ['required', 'string', 'max:255'],
            ],
        )->validate();

        $user = DB::transaction(function () use ($email, $name): User {
            $user = new User(['name' => $name, 'email' => $email, 'password' => Str::random(64)]);
            $user->is_platform_admin = true;
            $user->save();

            activity()
                ->performedOn($user)
                ->event('platform_user_created')
                ->log('platform_user_created');

            return $user;
        });

        $user->notify(new SetPasswordNotification);

        return $user;
    }
}
