<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\ReviewAssignmentStatus;
use App\Http\Requests\Reviews\DeclareConflictRequest;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewAssignment;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * El evaluador declara conflicto de interés sobre una propuesta que se le asignó (plan 011, H-011-4,
 * CA-011-17). Se ejecuta en la organización DE LA ASIGNACIÓN.
 *
 * La asignación pasa a `declined_conflict`: ya no se puede calificar (`ReviewPolicy::submit`), deja de contar
 * para la cobertura de la propuesta (los organizadores ven el aviso en su lista) y se conserva como evidencia.
 * Solo vale mientras la asignación sea `pending` o `in_progress`: un borrador queda guardado, pero una
 * evaluación ya enviada cuenta en los resultados y, si el conflicto aparece después, la cancela el
 * organizador (DA-7). No se puede deshacer desde el panel del evaluador. Queda auditado, sin texto libre.
 *
 * @param  array<string, mixed>  $data  `confirmed`: la confirmación explícita de la declaración
 *
 * @throws AuthorizationException si la asignación no es de quien declara, ya no está vigente, ya se envió o ya se declaró
 * @throws ValidationException si falta la confirmación
 */
final class DeclareConflictOfInterest
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, ReviewAssignment $assignment, array $data): ReviewAssignment
    {
        $organization = Organization::query()->findOrFail($assignment->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $assignment, $data): ReviewAssignment {
            $this->authorizeActor($actor, 'declareConflict', Review::class, $assignment);

            Validator::make($data, (new DeclareConflictRequest)->rules())->validate();

            return DB::transaction(function () use ($actor, $assignment): ReviewAssignment {
                $current = ReviewAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();

                // Pudo cambiar desde que se cargó la pantalla (enviada, cancelada, ya declarada).
                $this->authorizeActor($actor, 'declareConflict', Review::class, $current);

                $previous = $current->status;
                $current->forceFill(['status' => ReviewAssignmentStatus::DeclinedConflict])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($current)
                    ->event('review_conflict_declared')
                    ->withProperties([
                        'organization_id' => $current->organization_id,
                        'event_id' => $current->event_id,
                        'submission_id' => $current->submission_id,
                        'old' => ['status' => $previous->value],
                        'new' => ['status' => $current->status->value],
                    ])
                    ->log('review_conflict_declared');

                return $current;
            });
        });
    }
}
