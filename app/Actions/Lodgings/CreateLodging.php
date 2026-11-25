<?php

declare(strict_types=1);

namespace App\Actions\Lodgings;

use App\Models\Event;
use App\Models\Lodging;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Lodgings\LodgingRules;
use App\Support\Lodgings\StoresLodgingImages;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Da de alta un hospedaje en un evento (plan 030, CA-030-1, CA-030-2, CA-030-14). Solo el nombre es obligatorio;
 * el enlace de reserva debe ser http o https. Las imágenes (`images`, hasta 10) se guardan por su contenido en el
 * disco `media`. Sin orden explícito, queda al final de la lista. Se ejecuta en la organización DEL EVENTO, en una
 * transacción, y audita con identificadores. Si algo falla, no queda ningún archivo huérfano.
 *
 * @throws AuthorizationException si quien crea no tiene `lodgings.manage` en la organización del evento
 * @throws ValidationException si los datos no cumplen `LodgingRules` o alguna imagen no es aceptable
 */
final class CreateLodging
{
    use AuthorizesInOrganization, StoresLodgingImages;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Event $event, array $data): Lodging
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $data): Lodging {
            $this->authorizeActor($actor, 'create', Lodging::class, $event);

            $stored = [];

            try {
                return DB::transaction(function () use ($actor, $event, $data, &$stored): Lodging {
                    // Se bloquea el evento para que dos altas simultáneas no tomen el mismo orden.
                    Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

                    $validated = Validator::make(LodgingRules::trimmed($data), LodgingRules::rules())->validate();
                    $attributes = LodgingRules::forStorage($validated);
                    $attributes['position'] ??= ((int) Lodging::query()->where('event_id', $event->id)->max('position')) + 1;

                    $files = $this->uploadedImages($data);
                    $this->assertWithinLimit(0, count($files));

                    $lodging = new Lodging;
                    $lodging->event_id = $event->id;
                    $lodging->forceFill($attributes)->save();

                    $this->attachImages($lodging, $event, $files, $stored);

                    activity()
                        ->causedBy($actor)
                        ->performedOn($lodging)
                        ->event('lodging_created')
                        ->withProperties([
                            'organization_id' => $lodging->organization_id,
                            'event_id' => $event->id,
                            'lodging_id' => $lodging->id,
                            'images' => count($files),
                        ])
                        ->log('lodging_created');

                    return $lodging;
                });
            } catch (Throwable $exception) {
                $this->discardImages($event, $stored);

                throw $exception;
            }
        });
    }
}
