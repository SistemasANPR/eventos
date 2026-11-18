<?php

declare(strict_types=1);

namespace App\Actions\Calls;

use App\Http\Requests\Calls\UpdateTrackRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Track;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Calls\CallAttributes;
use App\Support\Events\OfferedLocaleTexts;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Edita nombre y descripción de un eje temático (plan 004, H-004-2, CA-004-12 y
 * CA-004-13). Se ejecuta en la organización DEL EJE. Deja auditoría con los
 * valores anteriores y los nuevos.
 *
 * @throws AuthorizationException si quien edita no tiene `tracks.manage` en la organización del eje
 * @throws ValidationException si falta el nombre en el idioma base del evento
 */
final class UpdateTrack
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Track $track, array $data): Track
    {
        $organization = Organization::query()->findOrFail($track->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $track, $data): Track {
            $this->authorizeActor($actor, 'update', $track);

            $event = Event::query()->findOrFail($track->event_id);
            $validated = Validator::make(OfferedLocaleTexts::discardIn($data, ['name', 'description'], $event), UpdateTrackRequest::rulesFor($event))->validate();

            return DB::transaction(function () use ($actor, $track, $validated, $event): Track {
                $previous = ['name' => $track->getTranslations('name'), 'description' => $track->getTranslations('description')];

                // Los textos de idiomas que el evento dejó de ofrecer se conservan (plan 026, DA-1).
                $track->forceFill([
                    'name' => OfferedLocaleTexts::withUnoffered(CallAttributes::texts($validated['name']), $previous['name'], $event),
                    'description' => OfferedLocaleTexts::withUnoffered(CallAttributes::texts($validated['description'] ?? []), $previous['description'], $event),
                ])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($track)
                    ->event('track_updated')
                    ->withProperties([
                        'organization_id' => $track->organization_id,
                        'old' => $previous,
                        'new' => ['name' => $track->getTranslations('name'), 'description' => $track->getTranslations('description')],
                    ])
                    ->log('track_updated');

                return $track;
            });
        });
    }
}
