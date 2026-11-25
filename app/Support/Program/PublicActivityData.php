<?php

declare(strict_types=1);

namespace App\Support\Program;

use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Speaker;
use App\Support\Html\RichTextSanitizer;
use App\Support\Tenancy\CurrentOrganization;
use LogicException;

/**
 * Lo que el sitio público sabe de una actividad (plan 029, DA-6, §4.3): una lista CERRADA de campos,
 * armada a mano; nunca `submission_id`, rutas de disco ni banderas internas, ni `toArray()` de un
 * modelo. La fecha y las horas son las LOCALES del evento, sin convertir (DA-5). La descripción se
 * vuelve a sanear al salir. Requiere cargadas las categorías y los ponentes visibles (los trae
 * `PublicProgramQuery::activityRelations`) y organización en contexto.
 */
final class PublicActivityData
{
    /**
     * Resumen (tarjetas y listas).
     *
     * @return array{id: string, title: string, summary: string|null, date: string, endDate: string, startTime: string, endTime: string, categories: list<array{id: string, name: string}>, speakers: list<array{id: string, name: string, company: string|null, jobTitle: string|null, tagline: string|null, photoUrl: string|null, url: string}>, iconUrl: string|null, url: string}
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
            // La página de detalle llega en F3 (`events.activities.show`); la ruta se construye igual que las demás.
            'url' => "/{$organization}/events/{$event->slug}/activities/{$activity->id}",
        ];
    }

    /**
     * Detalle completo.
     *
     * @return array{id: string, title: string, summary: string|null, date: string, endDate: string, startTime: string, endTime: string, categories: list<array{id: string, name: string}>, speakers: list<array{id: string, name: string, company: string|null, jobTitle: string|null, tagline: string|null, photoUrl: string|null, url: string}>, iconUrl: string|null, url: string, descriptionHtml: string|null, imageUrl: string|null}
     */
    public static function detail(EventActivity $activity, Event $event): array
    {
        $description = $activity->description === null ? '' : RichTextSanitizer::clean($activity->description);

        return [
            ...self::summary($activity, $event),
            'descriptionHtml' => $description === '' ? null : $description,
            'imageUrl' => self::mediaUrl($activity->image_path, 'image', CurrentOrganization::get()->slug, $event, $activity),
        ];
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
