<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Enums\EventAssignmentRole;
use App\Http\Requests\Events\AssignUserToEventRequest;
use App\Models\Event;
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
 * Asigna a un usuario a un evento con un rol (plan 011, H-011-7, CA-011-29, -30
 * y -32). Se ejecuta en la organización DEL EVENTO. El usuario debe ser
 * integrante activo de esa organización; la base lo refuerza con una clave
 * compuesta. Deja auditoría.
 *
 * @throws AuthorizationException si quien asigna no tiene `event_assignments.manage` en la organización del evento
 * @throws ValidationException si el rol no existe, el usuario no es integrante activo o la asignación ya existe
 */
final class AssignUserToEvent
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Event $event, array $data): EventAssignment
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $organization, $data): EventAssignment {
            $this->authorizeActor($actor, 'create', EventAssignment::class, $event);

            $validated = Validator::make($data, (new AssignUserToEventRequest)->rules())->validate();
            $role = EventAssignmentRole::from($validated['role']);

            $assignee = User::query()->activeMembersOf($organization)->whereKey($validated['user_id'])->first();
            if (! $assignee instanceof User) {
                throw ValidationException::withMessages(['user_id' => __('events.assignment_user_not_member')]);
            }

            return DB::transaction(function () use ($actor, $event, $assignee, $role): EventAssignment {
                $exists = EventAssignment::query()
                    ->where('event_id', $event->id)->where('user_id', $assignee->id)->where('role', $role)
                    ->lockForUpdate()->exists();
                if ($exists) {
                    throw ValidationException::withMessages(['role' => __('events.assignment_already_exists')]);
                }

                $assignment = new EventAssignment;
                $assignment->forceFill(['event_id' => $event->id, 'user_id' => $assignee->id, 'role' => $role])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($assignment)
                    ->event('event_assignment_created')
                    ->withProperties([
                        'organization_id' => $event->organization_id,
                        'event_id' => $event->id,
                        'user_id' => $assignee->id,
                        'new' => ['role' => $role->value],
                    ])
                    ->log('event_assignment_created');

                return $assignment;
            });
        });
    }
}
