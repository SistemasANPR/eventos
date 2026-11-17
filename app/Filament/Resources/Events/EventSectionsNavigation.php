<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events;

use App\Filament\Resources\Forms\FormDefinitionResource;
use App\Filament\Resources\Submissions\SubmissionResource;
use App\Models\ActivityCategory;
use App\Models\EventActivity;
use App\Models\Speaker;
use App\Models\SpeakerCategory;
use Closure;
use Filament\Navigation\NavigationItem;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

use function Filament\Support\original_request;

/**
 * Secciones del evento como ítems anidados bajo «Eventos» en el menú lateral (plan 020, CA-020-17).
 * Aparecen solo cuando la ruta trae un evento (su vista, edición, formularios o propuestas) y, además,
 * solo para quien puede acceder a cada sección. Ocultar una entrada no autoriza: cada ruta se niega por
 * su Policy. El evento sale de la petición original, así que también sirve en las peticiones de Livewire.
 */
final class EventSectionsNavigation
{
    /**
     * @param  string  $parentKey  clave del ítem «Eventos» bajo el que se anidan
     * @return array<int, NavigationItem>
     */
    public static function items(string $parentKey): array
    {
        return [
            self::section($parentKey, __('events.sub_navigation.summary'), Heroicon::OutlinedEye, 1, 'view', ['view', 'edit'], fn (): bool => EventResource::canAccess()),
            self::section($parentKey, __('forms.navigation'), Heroicon::OutlinedClipboardDocumentList, 2, 'form-definitions', ['form-definitions', 'form-definitions.*'], fn (): bool => FormDefinitionResource::canAccess()),
            self::section($parentKey, __('submission_panel.navigation'), Heroicon::OutlinedInboxArrowDown, 3, 'submissions', ['submissions', 'submissions.*'], fn (): bool => SubmissionResource::canAccess()),
            self::section($parentKey, __('program.speakers.navigation'), Heroicon::OutlinedMicrophone, 4, 'speakers', ['speakers'], fn (): bool => Gate::allows('viewAny', Speaker::class)),
            self::section($parentKey, __('program.activities.navigation'), Heroicon::OutlinedCalendarDays, 5, 'activities', ['activities'], fn (): bool => Gate::allows('viewAny', EventActivity::class)),
            self::section($parentKey, __('program.categories.speakers.navigation'), Heroicon::OutlinedTag, 6, 'speaker-categories', ['speaker-categories'], fn (): bool => Gate::allows('viewAny', SpeakerCategory::class)),
            self::section($parentKey, __('program.categories.activities.navigation'), Heroicon::OutlinedRectangleStack, 7, 'activity-categories', ['activity-categories'], fn (): bool => Gate::allows('viewAny', ActivityCategory::class)),
        ];
    }

    /**
     * @param  list<string>  $activeRoutes  rutas (sin el prefijo del recurso) en las que la sección está activa
     */
    private static function section(string $parentKey, string $label, Heroicon $icon, int $sort, string $page, array $activeRoutes, Closure $canAccess): NavigationItem
    {
        return NavigationItem::make($label)
            ->parentItem($parentKey)
            ->icon($icon)
            ->sort($sort)
            ->visible(fn (): bool => self::eventId() !== null && $canAccess())
            ->activeWhen(fn (): bool => original_request()->routeIs(
                array_map(fn (string $route): string => EventResource::getRouteBaseName().'.'.$route, $activeRoutes),
            ))
            ->url(fn (): ?string => ($id = self::eventId()) === null ? null : EventResource::getUrl($page, ['record' => $id]));
    }

    /** Evento de la ruta: el parámetro `event` de los recursos anidados o `record` de las páginas del propio evento. */
    private static function eventId(): ?string
    {
        $route = original_request()->route();

        if ($route === null) {
            return null;
        }

        $id = $route->parameter('event');

        if ($id === null && str_starts_with((string) $route->getName(), EventResource::getRouteBaseName().'.')) {
            $id = $route->parameter('record');
        }

        return is_string($id) && $id !== '' ? $id : null;
    }
}
