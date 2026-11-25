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
 * Da de alta un patrocinador en un evento (plan 030, CA-030-4, CA-030-5). Solo el nombre es obligatorio; el
 * sitio web debe ser http o https; el logotipo (`logo`) se guarda por su contenido en el disco `media`. Sin
 * orden explícito, queda al final de la lista. Se ejecuta en la organización DEL EVENTO, en una transacción,
 * y audita con identificadores. Si algo falla, no queda ningún archivo huérfano.
 *
 * @throws AuthorizationException si quien crea no tiene `sponsors.manage` en la organización del evento
 * @throws ValidationException si los datos no cumplen `SponsorRules` o el logotipo no es aceptable
 */
final class CreateSponsor
{
    use AuthorizesInOrganization, StoresSponsorLogo;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Event $event, array $data): Sponsor
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $data): Sponsor {
            $this->authorizeActor($actor, 'create', Sponsor::class, $event);

            $storedLogo = null;

            try {
                return DB::transaction(function () use ($actor, $event, $data, &$storedLogo): Sponsor {
                    // Se bloquea el evento para que dos altas simultáneas no tomen el mismo orden.
                    Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

                    $validated = Validator::make(SponsorRules::trimmed($data), SponsorRules::rules($event->id))->validate();
                    $attributes = SponsorRules::forStorage($validated);
                    $attributes['position'] ??= ((int) Sponsor::query()->where('event_id', $event->id)->max('position')) + 1;

                    $logo = $data['logo'] ?? null;

                    if ($logo instanceof UploadedFile) {
                        $storedLogo = $this->storeLogo($logo, $event);
                        $attributes['logo_path'] = $storedLogo;
                    }

                    $sponsor = new Sponsor;
                    $sponsor->event_id = $event->id;
                    $sponsor->forceFill($attributes)->save();

                    activity()
                        ->causedBy($actor)
                        ->performedOn($sponsor)
                        ->event('sponsor_created')
                        ->withProperties([
                            'organization_id' => $sponsor->organization_id,
                            'event_id' => $event->id,
                            'sponsor_id' => $sponsor->id,
                        ])
                        ->log('sponsor_created');

                    return $sponsor;
                });
            } catch (Throwable $exception) {
                if ($storedLogo !== null) {
                    $this->discardLogo($event, $storedLogo);
                }

                throw $exception;
            }
        });
    }
}
