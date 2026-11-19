<?php

declare(strict_types=1);

namespace App\Actions\Landings;

use App\Actions\Program\DeleteProgramImage;
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
 * Elimina una imagen de la landing (plan 022, DA-7, CA-022-33): la marca como eliminada y borra su
 * archivo. Una imagen que la versión PUBLICADA todavía usa no se elimina (rompería la página pública):
 * hay que quitarla de los bloques y actualizar primero. Auditoría `landing_image_deleted`.
 *
 * @throws AuthorizationException si quien elimina no tiene `events.manage` en la organización del evento
 * @throws ValidationException (campo `image`) si la versión publicada todavía usa la imagen
 */
final class DeleteLandingImage
{
    use AuthorizesInOrganization;

    public function __construct(private readonly DeleteProgramImage $delete) {}

    public function handle(User $actor, LandingImage $image): void
    {
        $organization = Organization::query()->findOrFail($image->organization_id);

        CurrentOrganization::run($organization, function () use ($actor, $image): void {
            $event = Event::query()->findOrFail($image->event_id);
            $this->authorizeActor($actor, 'updateLanding', $event);

            DB::transaction(function () use ($actor, $image): void {
                $landing = EventLanding::query()->whereKey($image->event_landing_id)->lockForUpdate()->firstOrFail();

                if (in_array($image->id, LandingBlocks::imageIds($landing->published_blocks ?? []), true)) {
                    throw ValidationException::withMessages(['image' => __('landings.errors.image_in_use')]);
                }

                $image->delete();

                activity()
                    ->causedBy($actor)
                    ->performedOn($image)
                    ->event('landing_image_deleted')
                    ->withProperties([
                        'organization_id' => $image->organization_id,
                        'event_id' => $image->event_id,
                        'landing_id' => $image->event_landing_id,
                        'image_id' => $image->id,
                    ])
                    ->log('landing_image_deleted');
            });

            $this->delete->handle($event, $image->path);
        });
    }
}
