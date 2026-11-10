<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Organizations\CreateOrganization;
use App\Exceptions\Domain\OrganizationAlreadyExists;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/** Crea una organización y su primer administrador (solo plataforma, por consola). */
final class CreateOrganizationCommand extends Command
{
    protected $signature = 'app:create-organization
        {slug : Identificador en la URL}
        {name : Nombre de la organización}
        {--admin-email= : Correo del primer administrador}
        {--admin-name= : Nombre del primer administrador}';

    protected $description = 'Crea una organización con su primer administrador (org_admin)';

    public function handle(CreateOrganization $createOrganization): int
    {
        try {
            $organization = $createOrganization->handle(
                (string) $this->argument('slug'),
                (string) $this->argument('name'),
                (string) $this->option('admin-email'),
                (string) $this->option('admin-name'),
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        } catch (OrganizationAlreadyExists $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Organización «{$organization->slug}» creada; se envió al administrador el correo para fijar su contraseña.");

        return self::SUCCESS;
    }
}
