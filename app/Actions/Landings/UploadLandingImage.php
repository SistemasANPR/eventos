<?php

declare(strict_types=1);

namespace App\Actions\Landings;

use App\Actions\Program\DeleteProgramImage;
use App\Actions\Program\StoreProgramImage;
use App\Enums\ProgramImageType;
use App\Exceptions\Domain\PrivateUploadFailed;
use App\Http\Requests\Landings\StoreLandingImageRequest;
use App\Models\Event;
use App\Models\EventLanding;
use App\Models\LandingImage;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Landings\LandingBlockRules;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sube una imagen de la landing (plan 022, DA-7, CA-022-29 a 31). El archivo se valida y se guarda con
 * `StoreProgramImage` (por su CONTENIDO: solo JPEG, PNG y WebP, hasta 5 MB y 40 millones de píxeles,
 * sin SVG ni código incrustado; nombre generado bajo `organizations/{org}/events/{evento}/media/landing/`)
 * y se registra en `landing_images`; los bloques guardan el identificador, no la ruta. Una landing admite
 * hasta 20 imágenes. Si algo falla no queda archivo huérfano. Auditoría `landing_image_uploaded` con
 * identificadores.
 *
 * @throws AuthorizationException si quien sube no tiene `events.manage` en la organización del evento
 * @throws ValidationException (campo `file`) si el archivo no es aceptable o la landing ya tiene 20 imágenes
 * @throws PrivateUploadFailed si el disco no lo guarda
 */
final class UploadLandingImage
{
    use AuthorizesInOrganization;

    public function __construct(
        private readonly StoreProgramImage $store,
        private readonly DeleteProgramImage $delete,
    ) {}

    public function handle(User $actor, EventLanding $landing, UploadedFile $file): LandingImage
    {
        $organization = Organization::query()->findOrFail($landing->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $landing, $file): LandingImage {
            $event = Event::query()->findOrFail($landing->event_id);
            $this->authorizeActor($actor, 'updateLanding', $event);

            Validator::make(['file' => $file], StoreLandingImageRequest::rulesFor())->validate();

            $storedPath = null;

            try {
                return DB::transaction(function () use ($actor, $event, $landing, $file, &$storedPath): LandingImage {
                    // Se bloquea la landing para que dos subidas simultáneas no rebasen el tope.
                    EventLanding::query()->whereKey($landing->id)->lockForUpdate()->firstOrFail();

                    if (LandingImage::query()->where('event_landing_id', $landing->id)->count() >= LandingBlockRules::MAX_IMAGES) {
                        throw ValidationException::withMessages(['file' => __('landings.errors.image_limit', ['max' => LandingBlockRules::MAX_IMAGES])]);
                    }

                    $storedPath = $this->store->handle($file, $event, ProgramImageType::Landing);
                    $path = (string) $file->getRealPath();
                    $info = getimagesize($path);

                    $image = new LandingImage;
                    $image->event_id = $event->id;
                    $image->forceFill([
                        'event_landing_id' => $landing->id,
                        'path' => $storedPath,
                        'mime' => is_array($info) ? $info['mime'] : 'application/octet-stream',
                        'size_bytes' => (int) filesize($path),
                        'width' => is_array($info) ? $info[0] : 0,
                        'height' => is_array($info) ? $info[1] : 0,
                    ])->save();

                    activity()
                        ->causedBy($actor)
                        ->performedOn($image)
                        ->event('landing_image_uploaded')
                        ->withProperties([
                            'organization_id' => $image->organization_id,
                            'event_id' => $event->id,
                            'landing_id' => $landing->id,
                            'image_id' => $image->id,
                        ])
                        ->log('landing_image_uploaded');

                    return $image;
                });
            } catch (Throwable $exception) {
                if ($storedPath !== null) {
                    $this->delete->handle($event, $storedPath);
                }

                throw $exception;
            }
        });
    }
}
