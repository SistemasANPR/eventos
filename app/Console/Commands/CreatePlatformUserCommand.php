<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Organizations\CreatePlatformUser;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/** Crea un usuario de plataforma (sin panel propio, RC-7): solo por consola. */
final class CreatePlatformUserCommand extends Command
{
    protected $signature = 'app:create-platform-user {email : Correo} {name : Nombre}';

    protected $description = 'Crea un usuario de plataforma y le envía el correo para fijar su contraseña';

    public function handle(CreatePlatformUser $createPlatformUser): int
    {
        try {
            $user = $createPlatformUser->handle((string) $this->argument('email'), (string) $this->argument('name'));
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info("Usuario de plataforma {$user->email} creado; se le envió el correo para fijar su contraseña.");

        return self::SUCCESS;
    }
}
