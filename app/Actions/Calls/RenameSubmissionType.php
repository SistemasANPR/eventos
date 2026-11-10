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
 * Renombra un tipo de propuesta (plan 004, DA-7, CA-004-31). Se ejecuta en la
 * organización DEL TIPO. Las convocatorias que lo usan muestran el nombre nuevo.
 * Deja auditoría con el nombre anterior y el nuevo.
 *
 * @throws AuthorizationException si quien edita no tiene `calls.manage` en la organización del tipo
 * @throws ValidationException si falta el nombre en el idioma base del evento
 */
final class RenameSubmissionType
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, SubmissionType $type, array $data): SubmissionType
    {
        $organization = Organization::query()->findOrFail($type->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $type, $data): SubmissionType {
            $this->authorizeActor($actor, 'update', $type);

            $event = Event::query()->findOrFail($type->event_id);
            $validated = Validator::make($data, StoreSubmissionTypeRequest::rulesFor($event))->validate();

            return DB::transaction(function () use ($actor, $type, $validated): SubmissionType {
                $previous = $type->getTranslations('name');
                $type->forceFill(['name' => CallAttributes::texts($validated['name'])])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($type)
                    ->event('submission_type_renamed')
                    ->withProperties(['organization_id' => $type->organization_id, 'old' => $previous, 'new' => $type->getTranslations('name')])
                    ->log('submission_type_renamed');

                return $type;
            });
        });
    }
}
