<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Models\Event;
use App\Models\Organization;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\TenantStoragePath;
use InvalidArgumentException;

/**
 * Borra una imagen del programa guardada con `StoreProgramImage` (plan 021). Solo borra rutas
 * del propio evento: una ruta de otro evento u otra organización se rechaza, para que un valor
 * manipulado no pueda apuntar a archivos ajenos. Un archivo que ya no existe cuenta como borrado.
 * Como `StoreProgramImage`, no autoriza: la llama quien ya comprobó el permiso.
 *
 * @throws InvalidArgumentException si la ruta no está bajo la carpeta de imágenes del evento
 */
final class DeleteProgramImage
{
    public function handle(Event $event, string $path): void
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        CurrentOrganization::run($organization, function () use ($event, $path): void {
            $prefix = TenantStoragePath::for("events/{$event->id}/media").'/';

            if (! str_starts_with($path, $prefix) || str_contains($path, '..')) {
                throw new InvalidArgumentException('La imagen no pertenece a este evento.');
            }

            MediaDisk::get()->delete($path);
        });
    }
}
