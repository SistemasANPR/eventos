<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Models\Event;
use App\Models\Speaker;
use App\Support\Program\PublicProgramQuery;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use App\Support\Tenancy\Exceptions\OrganizationMismatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Ponentes visibles de un evento, una página (plan 029, DA-6, DA-7, DA-11). Recibe el evento ya
 * acotado por el binding y repite el filtro por evento y organización. Un ponente publicado sin
 * actividades visibles sí aparece (es un invitado anunciado). Orden: apellido y nombre sin distinguir
 * mayúsculas ni acentos, y `id` para que la paginación sea estable. Página de 48 por defecto y 100 como tope.
 *
 * @return LengthAwarePaginator<int, Speaker>
 *
 * @throws MissingOrganizationContext sin organización en contexto
 * @throws OrganizationMismatch si el evento no es de la organización en contexto
 */
final class ListPublicSpeakers
{
    /** @return LengthAwarePaginator<int, Speaker> */
    public function handle(Event $event, int $page = 1, int $perPage = Speaker::PUBLIC_PER_PAGE): LengthAwarePaginator
    {
        $organizationId = PublicProgramQuery::organizationId($event);

        return Speaker::query()
            ->visibleToPublic()
            ->where('speakers.organization_id', $organizationId)
            ->where('speakers.event_id', $event->id)
            ->tap(static function (Builder $query): void {
                PublicProgramQuery::orderByText($query, 'speakers.last_name');
                PublicProgramQuery::orderByText($query, 'speakers.first_name');
            })
            ->orderBy('speakers.id')
            ->paginate(max(1, min($perPage, Speaker::PUBLIC_MAX_PER_PAGE)), ['speakers.*'], 'page', max(1, $page));
    }
}
