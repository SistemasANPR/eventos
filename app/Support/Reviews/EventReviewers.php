<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\EventAssignmentRole;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\Organization;
use App\Models\User;

/**
 * Quiénes pueden recibir propuestas de un evento (plan 011, CA-011-10): integrantes ACTIVOS de la organización
 * con una asignación de evento de rol `reviewer`. Lo usan `AssignReviewers` (rechaza la operación entera si
 * alguno no cumple) y `SyncScopedAssignments` (omite e informa). Debe llamarse con la organización en contexto.
 */
final class EventReviewers
{
    /**
     * @param  list<string>  $reviewerIds
     * @return array<string, User> los que cumplen, por identificador; los demás no aparecen
     */
    public static function available(Organization $organization, Event $event, array $reviewerIds): array
    {
        if ($reviewerIds === []) {
            return [];
        }

        $assigned = EventAssignment::query()
            ->where('event_id', $event->id)
            ->where('role', EventAssignmentRole::Reviewer)
            ->whereIn('user_id', $reviewerIds)
            ->pluck('user_id')
            ->all();

        return User::query()
            ->activeMembersOf($organization)
            ->whereIn('id', $assigned)
            ->get()
            ->keyBy('id')
            ->all();
    }
}
