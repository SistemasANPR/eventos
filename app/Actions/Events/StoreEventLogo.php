<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Program\DeleteProgramImage;
use App\Actions\Program\StoreProgramImage;
use App\Enums\ProgramImageType;
use App\Exceptions\Domain\PrivateUploadFailed;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sube o cambia el logotipo de un evento (plan 027, CA-027-1, 3, 4, 5, 7 y 8). El archivo se valida y
 * se guarda con `StoreProgramImage` por su CONTENIDO (PNG, JPEG o WebP de hasta 2 MB, sin SVG); la
 * columna `events.logo_path` solo guarda la ruta. El archivo anterior se borra una vez confirmada la
 * escritura: si algo falla antes, se borra el nuevo y el evento sigue apuntando al anterior, sin
 * huérfanos. Si la llama otra transacción (crear o editar el evento), el borrado del anterior espera
 * a que esa transacción confirme. Auditoría `event_logo_changed` con identificadores, sin contenido.
 *
 * @throws AuthorizationException si quien sube no tiene `events.manage` en la organización del evento
 * @throws ValidationException (campo `logo`) si el archivo no es aceptable
 * @throws PrivateUploadFailed si el disco no lo guarda
 */
final class StoreEventLogo
{
    use AuthorizesInOrganization;

    public function __construct(
        private readonly StoreProgramImage $store,
        private readonly DeleteProgramImage $delete,
    ) {}

    public function handle(User $actor, Event $event, UploadedFile $file): Event
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $file): Event {
            $this->authorizeActor($actor, 'update', $event);

            $storedPath = $this->store($file, $event);

            try {
                $replacedPath = DB::transaction(function () use ($actor, $event, $storedPath): ?string {
                    // Serializa los cambios de logotipo del evento: dos subidas a la vez no dejan un archivo huérfano.
                    $replaced = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail()->logo_path;

                    $event->forceFill(['logo_path' => $storedPath])->save();

                    activity()
                        ->causedBy($actor)
                        ->performedOn($event)
                        ->event('event_logo_changed')
                        ->withProperties(['organization_id' => $event->organization_id, 'event_id' => $event->id])
                        ->log('event_logo_changed');

                    return $replaced;
                });
            } catch (Throwable $exception) {
                $this->discard($event, $storedPath);

                throw $exception;
            }

            if ($replacedPath !== null) {
                DB::afterCommit(fn () => $this->discard($event, $replacedPath));
            }

            return $event;
        });
    }

    /** @throws ValidationException */
    private function store(UploadedFile $file, Event $event): string
    {
        try {
            return $this->store->handle($file, $event, ProgramImageType::EventLogo);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['logo' => $exception->errors()['file'] ?? $exception->getMessage()]);
        }
    }

    /** Borra un archivo que ya no se usa. Un fallo al borrar se registra y no deshace lo guardado. */
    private function discard(Event $event, string $path): void
    {
        try {
            $this->delete->handle($event, $path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
