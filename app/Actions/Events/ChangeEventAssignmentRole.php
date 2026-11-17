<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Enums\EventAssignmentRole;
use App\Http\Requests\Events\AssignUserToEventRequest;
use App\Models\EventAssignment;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Cambia el rol de una asignación de evento (plan 011, CA-011-31). Deja
 * auditoría con quién cambió, a quién y el rol anterior y el nuevo.
 *
 * @throws AuthorizationException si quien cambia no tiene `event_assignments.manage` en la organización de la asignación
 * @throws ValidationException si el rol no existe o el usuario ya lo tiene en ese evento
 */
final class ChangeEventAssignmentRole
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, EventAssignment $assignment, array $data): EventAssignment
    {
        $organization = Organization::query()->findOrFail($assignment->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $assignment, $data): EventAssignment {
            $this->authorizeActor($actor, 'update', $assignment);

            $validated = Validator::make($data, AssignUserToEventRequest::roleRules())->validate();
            $role = EventAssignmentRole::from($validated['role']);

            return DB::transaction(function () use ($actor, $assignment, $role): EventAssignment {
                $current = EventAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
                $previous = $current->role;

                if ($previous === $role) {
                    return $current;
                }

                $taken = EventAssignment::query()
                    ->where('event_id', $current->event_id)->where('user_id', $current->user_id)->where('role', $role)
                    ->exists();
                if ($taken) {
                    throw ValidationException::withMessages(['role' => __('events.assignment_already_exists')]);
                }

                $current->forceFill(['role' => $role])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($current)
                    ->event('event_assignment_role_changed')
                    ->withProperties([
                        'organization_id' => $current->organization_id,
                        'event_id' => $current->event_id,
                        'user_id' => $current->user_id,
                        'old' => ['role' => $previous->value],
                        'new' => ['role' => $role->value],
                    ])
                    ->log('event_assignment_role_changed');

                return $current;
            });
        });
    }
}
