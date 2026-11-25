<?php

declare(strict_types=1);

namespace App\Actions\Lodgings;

use App\Models\Event;
use App\Models\Lodging;
use App\Models\LodgingImage;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Lodgings\LodgingRules;
use App\Support\Lodgings\StoresLodgingImages;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Edita un hospedaje (plan 030, CA-030-2, CA-030-3, CA-030-14, CA-030-15). Solo cambia las claves que llegan en
 * `$data`; la validación corre sobre la ficha ya combinada (el nombre sigue siendo obligatorio). `images` agrega
 * imágenes al final de la galería y `remove_images` (identificadores de imágenes de ESTE hospedaje; los ajenos se
 * ignoran) quita las indicadas; el archivo de una imagen quitada se borra DESPUÉS de confirmar la transacción. Se
 * ejecuta en la organización DEL HOSPEDAJE y audita qué campos cambiaron (nombres de campo, sin los valores).
 *
 * @throws AuthorizationException si quien edita no tiene `lodgings.manage` en la organización del hospedaje
 * @throws ValidationException si los datos no cumplen `LodgingRules`, alguna imagen no es aceptable o la galería excede el tope
 */
final class UpdateLodging
{
    use AuthorizesInOrganization, StoresLodgingImages;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Lodging $lodging, array $data): Lodging
    {
        $organization = Organization::query()->findOrFail($lodging->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $lodging, $data): Lodging {
            $this->authorizeActor($actor, 'update', $lodging);

            $event = Event::query()->findOrFail($lodging->event_id);
            $stored = [];
            $removedPaths = [];

            try {
                $lodging = DB::transaction(function () use ($actor, $lodging, $event, $data, &$stored, &$removedPaths): Lodging {
                    $lodging->refresh();

                    $given = LodgingRules::trimmed($data);
                    $validated = Validator::make(
                        [...$lodging->only([...LodgingRules::TEXT_FIELDS, 'position']), ...$given],
                        LodgingRules::rules(),
                    )->validate();

                    $lodging->forceFill(LodgingRules::forStorage(array_intersect_key($validated, $given)))->save();
                    $changed = array_keys($lodging->getChanges());

                    $removeIds = array_values(array_filter(
                        is_array($data['remove_images'] ?? null) ? $data['remove_images'] : [],
                        static fn (mixed $id): bool => is_string($id) && Str::isUuid($id),
                    ));
                    $toRemove = $removeIds === []
                        ? collect()
                        : LodgingImage::query()->where('lodging_id', $lodging->id)->whereKey($removeIds)->get();
                    $files = $this->uploadedImages($data);

                    $this->assertWithinLimit(
                        LodgingImage::query()->where('lodging_id', $lodging->id)->count() - $toRemove->count(),
                        count($files),
                    );

                    foreach ($toRemove as $image) {
                        $removedPaths[] = $image->path;
                        $image->delete();
                    }

                    $this->attachImages($lodging, $event, $files, $stored);

                    if ($toRemove->isNotEmpty() || $files !== []) {
                        $changed[] = 'images';
                    }

                    activity()
                        ->causedBy($actor)
                        ->performedOn($lodging)
                        ->event('lodging_updated')
                        ->withProperties([
                            'organization_id' => $lodging->organization_id,
                            'event_id' => $lodging->event_id,
                            'lodging_id' => $lodging->id,
                            'changed' => $changed,
                        ])
                        ->log('lodging_updated');

                    return $lodging;
                });
            } catch (Throwable $exception) {
                $this->discardImages($event, $stored);

                throw $exception;
            }

            $this->discardImages($event, $removedPaths);

            return $lodging;
        });
    }
}
