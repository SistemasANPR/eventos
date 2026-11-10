<?php

declare(strict_types=1);

namespace App\Actions\Calls;

use App\Enums\MoveDirection;
use App\Models\Organization;
use App\Models\SubmissionType;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Sube o baja un tipo de propuesta una posición dentro de la lista de su evento
 * (plan 004, DA-7, CA-004-31): intercambia su posición con la del vecino. Subir
 * el primero o bajar el último no cambia nada. Se ejecuta en la organización
 * DEL TIPO y deja auditoría solo si algo se movió.
 *
 * @throws AuthorizationException si quien mueve no tiene `calls.manage` en la organización del tipo
 */
final class MoveSubmissionType
{
    use AuthorizesInOrganization;

    public function handle(User $actor, SubmissionType $type, MoveDirection $direction): SubmissionType
    {
        $organization = Organization::query()->findOrFail($type->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $type, $direction): SubmissionType {
            $this->authorizeActor($actor, 'update', $type);

            return DB::transaction(function () use ($actor, $type, $direction): SubmissionType {
                $siblings = SubmissionType::query()->where('event_id', $type->event_id)->lockForUpdate();
                $neighbour = $direction === MoveDirection::Up
                    ? $siblings->where('position', '<', $type->position)->orderByDesc('position')->first()
                    : $siblings->where('position', '>', $type->position)->orderBy('position')->first();

                if ($neighbour === null) {
                    return $type;
                }

                [$from, $to] = [$type->position, $neighbour->position];
                $neighbour->forceFill(['position' => $from])->save();
                $type->forceFill(['position' => $to])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($type)
                    ->event('submission_type_moved')
                    ->withProperties(['organization_id' => $type->organization_id, 'old' => ['position' => $from], 'new' => ['position' => $to]])
                    ->log('submission_type_moved');

                return $type;
            });
        });
    }
}
