<?php

declare(strict_types=1);

namespace App\Actions\Calls;

use App\Enums\CallStatus;
use App\Http\Requests\Calls\StoreCallForSubmissionsRequest;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Calls\CallAttributes;
use App\Support\Calls\CallSlug;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Crea una convocatoria en borrador (plan 004, CA-004-1 a 6). Se ejecuta en la
 * organización DEL EVENTO: quien no pertenece a ella no puede crearla. El
 * identificador de la URL se genera del título (CA-004-28). Las fechas llegan
 * como hora local del evento y se guardan en UTC.
 *
 * @throws AuthorizationException si quien crea no tiene `calls.manage` en la organización del evento
 * @throws ValidationException si los datos no son válidos (no se guarda nada)
 */
final class CreateCallForSubmissions
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Event $event, array $data): CallForSubmissions
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $data): CallForSubmissions {
            $this->authorizeActor($actor, 'create', CallForSubmissions::class, $event);

            $validated = Validator::make($data, StoreCallForSubmissionsRequest::rulesFor($event))->validate();

            return DB::transaction(function () use ($actor, $event, $validated): CallForSubmissions {
                $call = new CallForSubmissions;
                $call->event_id = $event->id;
                $call->status = CallStatus::Draft;
                $attributes = CallAttributes::fromValidated($validated, $event);
                $call->forceFill([...$attributes, 'slug' => CallSlug::for($event, $attributes['title'])])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($call)
                    ->event('call_created')
                    ->withProperties(['organization_id' => $call->organization_id, 'event_id' => $event->id, 'slug' => $call->slug])
                    ->log('call_created');

                return $call;
            });
        });
    }
}
