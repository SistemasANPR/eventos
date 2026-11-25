<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Models\ActivityCategory;
use App\Models\Event;
use App\Models\EventActivity;
use App\Support\Program\PublicProgramDay;
use App\Support\Program\PublicProgramQuery;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use App\Support\Tenancy\Exceptions\OrganizationMismatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Programa público de un evento, un día por vista (plan 029, DA-3, DA-4 y DA-11). Recibe el evento ya
 * acotado por el binding y repite el filtro por evento y organización. Solo actividades visibles
 * (`visibleToPublic`); se agrupan por su día de inicio, en la hora local del evento (sin convertir).
 * Un día que no es de la lista (vacío, fecha inventada, texto) cae al primer día: no es un error.
 * Orden dentro del día: hora de inicio, de fin, título e `id` (desempate para que la paginación no
 * repita ni pierda filas). Consultas constantes: sin N+1.
 *
 * @throws MissingOrganizationContext sin organización en contexto
 * @throws OrganizationMismatch si el evento no es de la organización en contexto
 */
final class ListPublicProgramDay
{
    public function handle(Event $event, ?string $day = null, int $page = 1, int $perPage = EventActivity::PUBLIC_PER_PAGE): PublicProgramDay
    {
        $organizationId = PublicProgramQuery::organizationId($event);

        $days = $this->visible($event, $organizationId)
            ->distinct()
            ->orderBy('starts_on')
            ->pluck('starts_on')
            ->map(static fn ($date): string => $date->toDateString())
            ->all();

        $shown = in_array($day, $days, true) ? $day : ($days[0] ?? null);

        if ($shown === null) {
            return new PublicProgramDay([], null, new LengthAwarePaginator([], 0, $perPage), new Collection);
        }

        $perPage = max(1, min($perPage, EventActivity::PUBLIC_MAX_PER_PAGE));

        $dayQuery = $this->visible($event, $organizationId)->where('event_activities.starts_on', $shown);

        $activities = (clone $dayQuery)
            ->with(PublicProgramQuery::activityRelations($event))
            ->orderBy('event_activities.starts_at')
            ->orderBy('event_activities.ends_at')
            ->tap(static fn (Builder $query) => PublicProgramQuery::orderByText($query, 'event_activities.title'))
            ->orderBy('event_activities.id')
            ->paginate($perPage, ['event_activities.*'], 'page', max(1, $page));

        $categories = ActivityCategory::query()
            ->where('activity_categories.event_id', $event->id)
            ->whereIn('activity_categories.id', static fn ($pivot) => $pivot
                ->select('activity_category_id')
                ->from('activity_activity_category')
                ->where('organization_id', $organizationId)
                ->whereIn('activity_id', $dayQuery->select('event_activities.id')))
            ->orderBy('activity_categories.position')
            ->tap(static fn (Builder $query) => PublicProgramQuery::orderByText($query, 'activity_categories.name'))
            ->get();

        return new PublicProgramDay($days, $shown, $activities, $categories);
    }

    /** @return Builder<EventActivity> */
    private function visible(Event $event, string $organizationId): Builder
    {
        return EventActivity::query()
            ->visibleToPublic()
            ->where('event_activities.organization_id', $organizationId)
            ->where('event_activities.event_id', $event->id);
    }
}
