<?php

declare(strict_types=1);

namespace App\Actions\Sponsors;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Sponsor;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Sponsors\SponsorRules;
use App\Support\Sponsors\StoresSponsorLogo;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Edita un patrocinador (plan 030, CA-030-5, CA-030-6). Solo cambia las claves que llegan en `$data`; la
 * validación corre sobre la ficha ya combinada. `logo` reemplaza el logotipo y `remove_logo` lo quita; el
 * archivo anterior se borra DESPUÉS de confirmar la transacción. Se ejecuta en la organización DEL PATROCINADOR
 * y audita qué campos cambiaron (nombres de campo, sin los valores).
 *
 * @throws AuthorizationException si quien edita no tiene `sponsors.manage` en la organización del patrocinador
 * @throws ValidationException si los datos no cumplen `SponsorRules` o el logotipo no es aceptable
 */
final class UpdateSponsor
{
    use AuthorizesInOrganization, StoresSponsorLogo;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Sponsor $sponsor, array $data): Sponsor
    {
        $organization = Organization::query()->findOrFail($sponsor->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $sponsor, $data): Sponsor {
            $this->authorizeActor($actor, 'update', $sponsor);

            $event = Event::query()->findOrFail($sponsor->event_id);
            $storedLogo = null;
            $replacedLogo = null;

            try {
                $sponsor = DB::transaction(function () use ($actor, $sponsor, $event, $data, &$storedLogo, &$replacedLogo): Sponsor {
                    $sponsor->refresh();

                    $given = SponsorRules::trimmed($data);
                    $validated = Validator::make(
                        [...$sponsor->only([...SponsorRules::TEXT_FIELDS, 'position']), ...$given],
                        SponsorRules::rules(),
                    )->validate();

                    $attributes = SponsorRules::forStorage(array_intersect_key($validated, $given));
                    $logo = $data['logo'] ?? null;
                    $logoChanged = false;

                    if ($logo instanceof UploadedFile) {
                        $storedLogo = $this->storeLogo($logo, $event);
                        $replacedLogo = $sponsor->logo_path;
                        $attributes['logo_path'] = $storedLogo;
                        $logoChanged = true;
                    } elseif (($data['remove_logo'] ?? false) === true && $sponsor->logo_path !== null) {
                        $replacedLogo = $sponsor->logo_path;
                        $attributes['logo_path'] = null;
                        $logoChanged = true;
                    }

                    $sponsor->forceFill($attributes)->save();

                    $changed = array_values(array_diff(array_keys($sponsor->getChanges()), ['logo_path', 'updated_at']));

                    if ($logoChanged) {
                        $changed[] = 'logo';
                    }

                    activity()
                        ->causedBy($actor)
                        ->performedOn($sponsor)
                        ->event('sponsor_updated')
                        ->withProperties([
                            'organization_id' => $sponsor->organization_id,
                            'event_id' => $sponsor->event_id,
                            'sponsor_id' => $sponsor->id,
                            'changed' => $changed,
                        ])
                        ->log('sponsor_updated');

                    return $sponsor;
                });
            } catch (Throwable $exception) {
                if ($storedLogo !== null) {
                    $this->discardLogo($event, $storedLogo);
                }

                throw $exception;
            }

            if ($replacedLogo !== null) {
                $this->discardLogo($event, $replacedLogo);
            }

            return $sponsor;
        });
    }
}
