<?php

declare(strict_types=1);

namespace App\Support\Program;

use App\Models\ActivityContact;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Room;
use App\Models\Speaker;
use App\Support\Html\RichTextSanitizer;
use App\Support\Tenancy\CurrentOrganization;
use LogicException;

/**
 * Lo que el sitio público sabe de una actividad (plan 029, DA-6, §4.3): una lista CERRADA de campos,
 * armada a mano; nunca `submission_id`, rutas de disco ni banderas internas, ni `toArray()` de un
 * modelo. La fecha y las horas son las LOCALES del evento, sin convertir (DA-5). La descripción se
 * vuelve a sanear al salir. Requiere cargadas las categorías y los ponentes visibles (los trae
 * `PublicProgramQuery::activityRelations`) y organización en contexto; el detalle, además, el lugar y los
 * responsables públicos (`PublicProgramQuery::activityDetailRelations`).
 *
 * Plan 035 (DA-5, DA-6, DA-13): el resumen solo dice si el cupo es limitado, sin números; el detalle suma el cupo, el
 * lugar (con su capacidad solo si el organizador la hizo pública), el punto de reunión y el transporte (solo de un
 * lugar externo) y los responsables marcados públicos. Los textos salen tal cual, como texto plano: la página los escapa.
 */
final class PublicActivityData
{
    /**
     * Resumen (tarjetas y listas).
     *
     * @return array{id: string, title: string, summary: string|null, date: string, endDate: string, startTime: string, endTime: string, categories: list<array{id: string, name: string}>, speakers: list<array{id: string, name: string, company: string|null, jobTitle: string|null, tagline: string|null, photoUrl: string|null, url: string}>, iconUrl: string|null, limitedSeats: bool, url: string}
     *
     * @throws LogicException si la actividad está sin programar: nunca debe llegar al público
     */
    public static function summary(EventActivity $activity, Event $event): array
    {
        $organization = CurrentOrganization::get()->slug;
        $startsOn = $activity->starts_on ?? throw new LogicException('Una actividad sin programar no se publica');
        $startsAt = $activity->starts_at ?? throw new LogicException('Una actividad sin hora no se publica');

        return [
            'id' => $activity->id,
            'title' => $activity->title,
            'summary' => $activity->summary,
            'date' => $startsOn->toDateString(),
            'endDate' => ($activity->ends_on ?? $startsOn)->toDateString(),
            'startTime' => self::hourAndMinute($startsAt),
            'endTime' => self::hourAndMinute($activity->ends_at ?? $startsAt),
            'categories' => $activity->categories
                ->map(static fn ($category): array => ['id' => $category->id, 'name' => $category->name])
                ->values()
                ->all(),
            'speakers' => $activity->speakers
                ->map(static fn (Speaker $speaker): array => PublicSpeakerData::summary($speaker, $event))
                ->values()
                ->all(),
            'iconUrl' => self::mediaUrl($activity->icon_path, 'icon', $organization, $event, $activity),
            'limitedSeats' => $activity->quota !== null,
            'url' => route('events.activities.show', ['organization' => $organization, 'event' => $event->slug, 'activity' => $activity->id], false),
        ];
    }

    /**
     * Detalle completo.
     *
     * @return array{id: string, title: string, summary: string|null, date: string, endDate: string, startTime: string, endTime: string, categories: list<array{id: string, name: string}>, speakers: list<array{id: string, name: string, company: string|null, jobTitle: string|null, tagline: string|null, photoUrl: string|null, url: string}>, iconUrl: string|null, limitedSeats: bool, url: string, descriptionHtml: string|null, imageUrl: string|null, seats: int|null, place: array{name: string, isExternal: bool, address: string|null, mapUrl: string|null, capacity: int|null}|null, meetingPoint: string|null, transport: string|null, contacts: list<array{name: string, phone: string|null, email: string|null}>}
     */
    public static function detail(EventActivity $activity, Event $event): array
    {
        $description = $activity->description === null ? '' : RichTextSanitizer::clean($activity->description);
        $room = $activity->room_id === null ? null : $activity->room;
        $external = $room?->kind->allowsOverlap() === true;

        return [
            ...self::summary($activity, $event),
            'descriptionHtml' => $description === '' ? null : $description,
            'imageUrl' => self::mediaUrl($activity->image_path, 'image', CurrentOrganization::get()->slug, $event, $activity),
            'seats' => $activity->quota,
            'place' => $room === null ? null : self::place($room),
            // Lo de un lugar externo solo se publica si el lugar lo es: un residuo en una sala nunca sale.
            'meetingPoint' => $external ? $activity->meeting_point : null,
            'transport' => $external ? $activity->transport_info : null,
            'contacts' => $activity->contacts
                ->filter(static fn (ActivityContact $contact): bool => $contact->is_public)
                ->map(static fn (ActivityContact $contact): array => ['name' => $contact->name, 'phone' => $contact->phone, 'email' => $contact->email])
                ->values()
                ->all(),
        ];
    }

    /**
     * El lugar de la actividad. La capacidad solo sale si el organizador la hizo pública y el enlace del mapa solo si es https.
     *
     * @return array{name: string, isExternal: bool, address: string|null, mapUrl: string|null, capacity: int|null}
     */
    private static function place(Room $room): array
    {
        return [
            'name' => $room->name,
            'isExternal' => $room->kind->allowsOverlap(),
            'address' => $room->address,
            'mapUrl' => self::httpsUrl($room->map_url),
            'capacity' => $room->capacity_is_public ? $room->capacity : null,
        ];
    }

    /** Un enlace solo se emite si es una URL https válida; todo lo demás (http, javascript:, relativo, texto) es nulo. */
    private static function httpsUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        return str_starts_with(strtolower($url), 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }

    /** `H:i:s` de la base a `HH:MM`. */
    private static function hourAndMinute(string $time): string
    {
        return substr($time, 0, 5);
    }

    private static function mediaUrl(?string $path, string $type, string $organization, Event $event, EventActivity $activity): ?string
    {
        return $path === null
            ? null
            : route('public.activities.media', ['organization' => $organization, 'event' => $event->slug, 'activity' => $activity->id, 'type' => $type], false);
    }
}
