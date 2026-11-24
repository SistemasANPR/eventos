<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Models\Event;
use App\Models\Speaker;
use App\Support\Program\PublicProgramQuery;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use App\Support\Tenancy\Exceptions\OrganizationMismatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Ficha pública de un ponente (plan 029, DA-6, DA-7, CA-029-9): el ponente visible de ESE evento con
 * sus categorías y SOLO sus actividades visibles (con sus categorías y ponentes visibles) en orden de
 * día y hora. Devuelve `null` si no existe o no es visible (publicado, no oculto, no borrado), si es de
 * otro evento u organización o si el identificador no es un uuid: el llamador responde 404 uniforme.
 *
 * @throws MissingOrganizationContext sin organización en contexto
 * @throws OrganizationMismatch si el evento no es de la organización en contexto
 */
final class FindPublicSpeaker
{
    public function handle(Event $event, string $speakerId): ?Speaker
    {
        $organizationId = PublicProgramQuery::organizationId($event);

        if (! Str::isUuid($speakerId)) {
            return null;
        }

        return Speaker::query()
            ->visibleToPublic()
            ->where('speakers.organization_id', $organizationId)
            ->where('speakers.event_id', $event->id)
            ->whereKey($speakerId)
            ->with([
                'categories' => static function (BelongsToMany $categories) use ($event): void {
                    $categories->where('speaker_categories.event_id', $event->id)->orderBy('speaker_categories.position');
                    PublicProgramQuery::orderByText($categories, 'speaker_categories.name');
                },
                'activities' => static function (BelongsToMany $activities) use ($event): void {
                    $activities->visibleToPublic()
                        ->where('event_activities.event_id', $event->id)
                        ->with(PublicProgramQuery::activityRelations($event))
                        ->orderBy('event_activities.starts_on')
                        ->orderBy('event_activities.starts_at')
                        ->orderBy('event_activities.ends_at')
                        ->tap(static fn (Builder|BelongsToMany $query) => PublicProgramQuery::orderByText($query, 'event_activities.title'))
                        ->orderBy('event_activities.id');
                },
            ])
            ->first();
    }
}
