<?php

declare(strict_types=1);

namespace App\Actions\Lodgings;

use App\Models\Lodging;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Lodgings\LodgingRules;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Edita un hospedaje (plan 030, CA-030-2, CA-030-3). Solo cambia las claves que llegan en `$data`; la
 * validación corre sobre la ficha ya combinada (el nombre sigue siendo obligatorio). Se ejecuta en la
 * organización DEL HOSPEDAJE y audita qué campos cambiaron (nombres de campo, sin los valores).
 *
 * @throws AuthorizationException si quien edita no tiene `lodgings.manage` en la organización del hospedaje
 * @throws ValidationException si los datos no cumplen `LodgingRules`
 */
final class UpdateLodging
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Lodging $lodging, array $data): Lodging
    {
        $organization = Organization::query()->findOrFail($lodging->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $lodging, $data): Lodging {
            $this->authorizeActor($actor, 'update', $lodging);

            return DB::transaction(function () use ($actor, $lodging, $data): Lodging {
                $lodging->refresh();

                $given = LodgingRules::trimmed($data);
                $validated = Validator::make(
                    [...$lodging->only([...LodgingRules::TEXT_FIELDS, 'position']), ...$given],
                    LodgingRules::rules(),
                )->validate();

                $lodging->forceFill(LodgingRules::forStorage(array_intersect_key($validated, $given)))->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($lodging)
                    ->event('lodging_updated')
                    ->withProperties([
                        'organization_id' => $lodging->organization_id,
                        'event_id' => $lodging->event_id,
                        'lodging_id' => $lodging->id,
                        'changed' => array_keys($lodging->getChanges()),
                    ])
                    ->log('lodging_updated');

                return $lodging;
            });
        });
    }
}
