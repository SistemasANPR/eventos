<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Enums\EventStatus;
use App\Http\Requests\Events\StoreEventRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Events\EventAttributes;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Crea un evento en borrador dentro de una organización (plan 003, CA-003-1 a 4).
 * Las fechas llegan como hora local del evento y se guardan en UTC. Deja
 * auditoría de quién lo creó.
 *
 * @throws AuthorizationException si quien crea no tiene `events.manage` en la organización
 * @throws ValidationException si los datos no son válidos (no se guarda nada)
 */
final class CreateEvent
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Organization $organization, array $data): Event
    {
        return CurrentOrganization::run($organization, function () use ($actor, $organization, $data): Event {
            $this->authorizeActor($actor, 'create', Event::class);

            /** @var list<string> $locales */
            $locales = (array) ($data['locales'] ?? []);
            $validated = Validator::make($data, StoreEventRequest::rulesFor($organization->id, $locales))->validate();

            return DB::transaction(function () use ($actor, $validated): Event {
                $event = Event::query()->create([
                    ...EventAttributes::fromValidated($validated),
                    'status' => EventStatus::Draft,
                ]);

                activity()
                    ->causedBy($actor)
                    ->performedOn($event)
                    ->event('event_created')
                    ->withProperties(['organization_id' => $event->organization_id])
                    ->log('event_created');

                return $event;
            });
        });
    }
}
