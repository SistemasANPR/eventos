<?php

declare(strict_types=1);

namespace App\Actions\Calls;

use App\Http\Requests\Calls\StoreCallForSubmissionsRequest;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Calls\CallAttributes;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Edita una convocatoria (plan 004, CA-004-1 a 7 y 11). Se ejecuta en la
 * organización DE LA CONVOCATORIA. El evento y la URL no cambian. Mover `closes_at` al
 * instante actual es el cierre de emergencia: el estado público es `closed` en
 * la petición siguiente; el cierre debe seguir siendo posterior a la apertura.
 * Deja auditoría con el valor anterior y el nuevo de cada campo que cambió.
 *
 * @throws AuthorizationException si quien edita no tiene `calls.manage` en la organización de la convocatoria
 * @throws ValidationException si los datos no son válidos
 */
final class UpdateCallForSubmissions
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, CallForSubmissions $call, array $data): CallForSubmissions
    {
        $organization = Organization::query()->findOrFail($call->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $call, $data): CallForSubmissions {
            $this->authorizeActor($actor, 'update', $call);

            $event = Event::query()->findOrFail($call->event_id);
            $validated = Validator::make($data, StoreCallForSubmissionsRequest::rulesFor($event))->validate();

            return DB::transaction(function () use ($actor, $call, $event, $validated): CallForSubmissions {
                $call->forceFill(CallAttributes::fromValidated($validated, $event));
                [$old, $new] = $this->changes($call);
                $call->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($call)
                    ->event('call_updated')
                    ->withProperties(['organization_id' => $call->organization_id, 'old' => $old, 'new' => $new])
                    ->log('call_updated');

                return $call;
            });
        });
    }

    /**
     * Valores anterior y nuevo de los campos que cambian (fechas en UTC ISO 8601).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function changes(CallForSubmissions $call): array
    {
        $old = [];
        $new = [];

        foreach ($call->getDirty() as $field => $value) {
            $old[$field] = $this->printable($call->getOriginal($field));
            $new[$field] = $this->printable($value);
        }

        return [$old, $new];
    }

    private function printable(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d\TH:i:s\Z') : $value;
    }
}
