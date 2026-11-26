<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Models\Event;
use App\Models\EventActivity;
use App\Support\Program\PublicProgramQuery;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use App\Support\Tenancy\Exceptions\OrganizationMismatch;
use Illuminate\Support\Str;

/**
 * Detalle público de una actividad (plan 029, DA-3, DA-7, CA-029-2, 3 y 10): la actividad visible de ESE
 * evento (publicada, no oculta por una reversa, programada y no borrada) con sus categorías y SOLO sus
 * ponentes visibles, en el orden de `position`, su lugar y sus responsables públicos (plan 035). Devuelve `null` si no existe o no es visible, si es de
 * otro evento u organización o si el identificador no es un uuid: el llamador responde 404 uniforme.
 *
 * @throws MissingOrganizationContext sin organización en contexto
 * @throws OrganizationMismatch si el evento no es de la organización en contexto
 */
final class FindPublicActivity
{
    public function handle(Event $event, string $activityId): ?EventActivity
    {
        $organizationId = PublicProgramQuery::organizationId($event);

        if (! Str::isUuid($activityId)) {
            return null;
        }

        return EventActivity::query()
            ->visibleToPublic()
            ->where('event_activities.organization_id', $organizationId)
            ->where('event_activities.event_id', $event->id)
            ->whereKey($activityId)
            ->with([...PublicProgramQuery::activityRelations($event), ...PublicProgramQuery::activityDetailRelations($event)])
            ->first();
    }
}
