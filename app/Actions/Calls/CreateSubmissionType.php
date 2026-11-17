<?php

declare(strict_types=1);

namespace App\Actions\Calls;

use App\Http\Requests\Calls\StoreSubmissionTypeRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Models\SubmissionType;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Calls\CallAttributes;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Agrega un tipo de propuesta a un evento (plan 004, DA-7, CA-004-30): queda al
 * final de la lista y solo existe en ese evento. Se ejecuta en la organización
 * DEL EVENTO. Deja auditoría.
 *
 * @throws AuthorizationException si quien crea no tiene `calls.manage` en la organización del evento
 * @throws ValidationException si falta el nombre en el idioma base del evento
 */
final class CreateSubmissionType
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Event $event, array $data): SubmissionType
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $data): SubmissionType {
            $this->authorizeActor($actor, 'create', SubmissionType::class, $event);

            $validated = Validator::make($data, StoreSubmissionTypeRequest::rulesFor($event))->validate();

            return DB::transaction(function () use ($actor, $event, $validated): SubmissionType {
                // Se bloquea el evento para que dos altas simultáneas no tomen la misma posición.
                Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                $last = SubmissionType::query()->where('event_id', $event->id)->max('position');

                $type = new SubmissionType;
                $type->event_id = $event->id;
                $type->forceFill([
                    'name' => CallAttributes::texts($validated['name']),
                    'position' => (int) $last + 1,
                ])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($type)
                    ->event('submission_type_created')
                    ->withProperties(['organization_id' => $type->organization_id, 'event_id' => $event->id, 'name' => $type->getTranslations('name')])
                    ->log('submission_type_created');

                return $type;
            });
        });
    }
}
