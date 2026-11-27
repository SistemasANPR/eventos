<?php

declare(strict_types=1);

namespace App\Support\Program;

use App\Models\Event;
use App\Models\Speaker;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use App\Support\Tenancy\Exceptions\OrganizationMismatch;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Reglas de consulta que comparten las Actions de lectura pública del programa (plan 029, §4.2):
 * quién es la organización, cómo se cargan las relaciones sin N+1 y con la misma restricción de
 * visibilidad y de evento, y cómo se ordena el texto.
 */
final class PublicProgramQuery
{
    /**
     * Intercalación ICU sin idioma: ordena sin distinguir mayúsculas y con los acentos del español en su
     * sitio («Álvarez» junto a «Alvarez»). La del servidor no basta: en las imágenes de PostgreSQL de
     * Alpine `en_US.utf8` compara por punto de código y manda «Álvarez» después de «Zúñiga», y `lower()`
     * no lo arregla (DA-11, medido en F1). Sin migración.
     */
    private const TEXT_COLLATION = 'und-x-icu';

    /**
     * Organización del contexto, comprobando que el evento es suyo. Sin contexto lanza
     * `MissingOrganizationContext`: una consulta pública no devuelve vacío por falta de contexto.
     *
     * @throws MissingOrganizationContext
     * @throws OrganizationMismatch
     */
    public static function organizationId(Event $event): string
    {
        $organizationId = CurrentOrganization::id();

        if ($event->organization_id !== $organizationId) {
            throw new OrganizationMismatch('El evento no es de la organización en contexto');
        }

        return $organizationId;
    }

    /**
     * Ordena por una columna de texto con la intercalación del programa. `$column` es siempre un
     * identificador fijo del código que llama, nunca un valor del cliente; se escapa con la gramática.
     *
     * @param  Builder<*>|Relation<*, *, *>  $query
     */
    public static function orderByText(Builder|Relation $query, string $column): void
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);

        $query->orderByRaw($wrapped.' collate "'.self::TEXT_COLLATION.'"');
    }

    /**
     * Relaciones que solo necesita el DETALLE de una actividad (plan 035): su lugar y SOLO los responsables marcados
     * públicos, en su orden, acotados al evento. El resumen no las usa.
     *
     * @return array<string, Closure(Relation<*, *, *>): void>
     */
    public static function activityDetailRelations(Event $event): array
    {
        return [
            'room' => static function (Relation $room) use ($event): void {
                /** @var Builder<Model> $query */
                $query = $room->getQuery();
                $query->where('rooms.event_id', $event->id);
            },
            'contacts' => static function (Relation $contacts) use ($event): void {
                /** @var Builder<Model> $query */
                $query = $contacts->getQuery();
                $query->where('activity_contacts.event_id', $event->id)->where('activity_contacts.is_public', true)->orderBy('activity_contacts.position');
            },
        ];
    }

    /**
     * Relaciones de una actividad que se muestra al público: las categorías del evento en orden y SOLO
     * los ponentes visibles del evento, en el orden de `position` del pivote (que la relación ya trae).
     *
     * @return array<string, Closure(BelongsToMany<*, *, *>): void>
     */
    public static function activityRelations(Event $event): array
    {
        return [
            'categories' => static function (BelongsToMany $categories) use ($event): void {
                $categories->where('activity_categories.event_id', $event->id)->orderBy('activity_categories.position');
                self::orderByText($categories, 'activity_categories.name');
            },
            'speakers' => static function (BelongsToMany $speakers) use ($event): void {
                /** @var Builder<Speaker> $query */
                $query = $speakers->getQuery();
                $query->visibleToPublic()->where('speakers.event_id', $event->id)->orderBy('speakers.id');
            },
        ];
    }
}
