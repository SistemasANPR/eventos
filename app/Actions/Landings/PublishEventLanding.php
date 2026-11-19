<?php

declare(strict_types=1);

namespace App\Actions\Landings;

use App\Actions\Program\DeleteProgramImage;
use App\Exceptions\Domain\LandingVersionConflict;
use App\Models\Event;
use App\Models\EventLanding;
use App\Models\LandingImage;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Landings\LandingBlocks;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Actualizar»: publica la landing (plan 022, DA-12, CA-022-16, 19, 20, 33). La versión publicada pasa a
 * ser una copia del borrador, con la fecha y quién publicó; la `version` sube y se registra
 * `landing_published` (con identificadores, sin contenido).
 *
 * Con `$blocks` guarda antes esos bloques como borrador (con las mismas reglas y el mismo control de
 * versión que `SaveLandingDraft`) en la MISMA transacción: «guardar y publicar» es una sola operación, y si
 * cualquier paso falla la versión publicada anterior queda intacta. Sin `$blocks` publica el borrador ya
 * guardado, sin exigir versión.
 *
 * Idempotente: si el borrador ya es lo publicado no cambia nada, no sube la versión ni duplica la
 * auditoría. Las imágenes que ya no usa ningún bloque se marcan como eliminadas y dejan de servirse (el
 * archivo se borra tras confirmar la transacción).
 *
 * @param  mixed  $blocks
 *
 * @throws AuthorizationException si quien publica no tiene `events.manage` en la organización del evento
 * @throws ValidationException si los bloques no son válidos
 * @throws LandingVersionConflict si se pasan bloques y la landing cambió desde la versión del editor
 */
final class PublishEventLanding
{
    use AuthorizesInOrganization;

    public function __construct(
        private readonly SaveLandingDraft $saveDraft,
        private readonly DeleteProgramImage $deleteImage,
    ) {}

    public function handle(User $actor, EventLanding $landing, mixed $blocks = null, ?int $expectedVersion = null): EventLanding
    {
        $organization = Organization::query()->findOrFail($landing->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $landing, $blocks, $expectedVersion): EventLanding {
            $event = Event::query()->findOrFail($landing->event_id);
            $this->authorizeActor($actor, 'publishLanding', $event);

            [$published, $removedPaths] = DB::transaction(function () use ($actor, $landing, $blocks, $expectedVersion): array {
                $current = EventLanding::query()->whereKey($landing->id)->lockForUpdate()->firstOrFail();

                if ($blocks !== null) {
                    $current = $this->saveDraft->handle($actor, $current, $blocks, $expectedVersion ?? $current->version);
                }

                if (! $current->hasUnpublishedChanges()) {
                    return [$current, []];
                }

                $current->forceFill([
                    'published_blocks' => $current->draft_blocks,
                    'published_at' => now(),
                    'published_by' => $actor->id,
                    'version' => $current->version + 1,
                ])->save();

                $removed = $this->removeUnusedImages($current);

                activity()
                    ->causedBy($actor)
                    ->performedOn($current)
                    ->event('landing_published')
                    ->withProperties([
                        'organization_id' => $current->organization_id,
                        'event_id' => $current->event_id,
                        'landing_id' => $current->id,
                        'version' => $current->version,
                    ])
                    ->log('landing_published');

                return [$current, $removed];
            });

            // Los archivos se borran con la transacción ya confirmada: un fallo de disco se ve, sin deshacer lo publicado.
            foreach ($removedPaths as $path) {
                $this->deleteImage->handle($event, $path);
            }

            return $published;
        });
    }

    /**
     * Marca como eliminadas las imágenes de la landing que ningún bloque publicado usa (CA-022-33).
     *
     * @return list<string> rutas de los archivos a borrar tras confirmar
     */
    private function removeUnusedImages(EventLanding $landing): array
    {
        $used = LandingBlocks::imageIds($landing->published_blocks ?? []);
        $paths = [];

        foreach (LandingImage::query()->where('event_landing_id', $landing->id)->whereNotIn('id', $used)->get() as $image) {
            $image->delete();
            $paths[] = $image->path;
        }

        return $paths;
    }
}
