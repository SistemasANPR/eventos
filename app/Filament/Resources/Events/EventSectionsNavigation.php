<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events;

use App\Filament\Resources\Forms\FormDefinitionResource;
use App\Filament\Resources\Submissions\SubmissionResource;
use App\Models\EventActivity;
use App\Models\Lodging;
use App\Models\Review;
use App\Models\ReviewAssignment;
use App\Models\Rubric;
use App\Models\Speaker;
use App\Models\Sponsor;
use Closure;
use Filament\Navigation\NavigationItem;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

use function Filament\Support\original_request;

/**
 * Secciones del evento como grupos del menú lateral, por fase del evento: Convocatoria, Evaluación,
 * Programa y Logística (plan 020, CA-020-17 y CA-020-18). Aparecen solo cuando la ruta trae un evento (su vista,
 * edición, formularios o propuestas) y, además, solo para quien puede acceder a cada sección; un grupo
 * sin entradas visibles no se muestra. Ocultar una entrada no autoriza: cada ruta se niega por su Policy.
 * El evento sale de la petición original, así que también sirve en las peticiones de Livewire.
 *
 * El orden de los grupos sale del `sort` de su primera entrada: Convocatoria (1 a 3), Evaluación (4 a 7)
 * Programa (8 y 9) y Logística (10 y 11) van después de «Eventos» y antes del grupo «Organización».
 */
final class EventSectionsNavigation
{
    /** @return array<int, NavigationItem> */
    public static function items(): array
    {
        $call = static fn (): string => __('navigation.groups.call');
        $evaluation = static fn (): string => __('navigation.groups.evaluation');
        $program = static fn (): string => __('navigation.groups.program');
        $logistics = static fn (): string => __('navigation.groups.logistics');

        return [
            self::section($call, __('events.sub_navigation.summary'), Heroicon::OutlinedEye, 1, 'view', ['view', 'edit'], fn (): bool => EventResource::canAccess()),
            self::section($call, __('forms.navigation'), Heroicon::OutlinedClipboardDocumentList, 2, 'form-definitions', ['form-definitions', 'form-definitions.*'], fn (): bool => FormDefinitionResource::canAccess()),
            self::section($call, __('submission_panel.navigation'), Heroicon::OutlinedInboxArrowDown, 3, 'submissions', ['submissions', 'submissions.*'], fn (): bool => SubmissionResource::canAccess()),
            // Las categorías no tienen entrada propia: se llega a ellas desde el botón «Categorías» de cada lista,
            // que por eso mantiene activa su sección en el menú.
            // Crear y editar una rúbrica son páginas de su recurso anidado: la sección sigue activa en ellas.
            // La rúbrica se define antes de asignar evaluadores, por eso va primero.
            self::section($evaluation, __('rubrics.navigation'), Heroicon::OutlinedScale, 4, 'rubrics', ['rubrics', 'rubrics.*'], fn (): bool => Gate::allows('viewAny', Rubric::class)),
            // Las reglas de alcance se alcanzan desde un botón de «Evaluadores», que por eso mantiene activa su sección.
            self::section($evaluation, __('review_assignments.reviewers.navigation'), Heroicon::OutlinedUserGroup, 5, 'reviewers', ['reviewers', 'assignment-rules'], fn (): bool => Gate::allows('viewAny', ReviewAssignment::class)),
            // Resultados y avance por evaluador (plan 011, F6): cada uno con su propio permiso.
            self::section($evaluation, __('review_results.results.navigation'), Heroicon::OutlinedChartBar, 6, 'review-results', ['review-results'], fn (): bool => Gate::allows('viewResults', [Review::class])),
            self::section($evaluation, __('review_results.progress.navigation'), Heroicon::OutlinedChartPie, 7, 'review-progress', ['review-progress', 'review-progress-detail'], fn (): bool => Gate::allows('viewProgress', [Review::class])),
            self::section($program, __('program.speakers.navigation'), Heroicon::OutlinedMicrophone, 8, 'speakers', ['speakers', 'speaker-categories'], fn (): bool => Gate::allows('viewAny', Speaker::class)),
            self::section($program, __('program.activities.navigation'), Heroicon::OutlinedCalendarDays, 9, 'activities', ['activities', 'activity-categories'], fn (): bool => Gate::allows('viewAny', EventActivity::class)),
            // Hospedaje y Patrocinadores (plan 030): grupo propio, después de Programa y antes de Organización.
            self::section($logistics, __('lodgings.navigation'), Heroicon::OutlinedBuildingOffice2, 10, 'lodgings', ['lodgings'], fn (): bool => Gate::allows('viewAny', Lodging::class)),
            self::section($logistics, __('sponsors.navigation'), Heroicon::OutlinedStar, 11, 'sponsors', ['sponsors', 'sponsor-categories'], fn (): bool => Gate::allows('viewAny', Sponsor::class)),
        ];
    }

    /**
     * @param  Closure(): string  $group  etiqueta del grupo, resuelta al armar el menú (el idioma ya está fijado)
     * @param  list<string>  $activeRoutes  rutas (sin el prefijo del recurso) en las que la sección está activa
     */
    private static function section(Closure $group, string $label, Heroicon $icon, int $sort, string $page, array $activeRoutes, Closure $canAccess): NavigationItem
    {
        return NavigationItem::make($label)
            ->group($group)
            ->icon($icon)
            ->sort($sort)
            ->visible(fn (): bool => self::eventId() !== null && $canAccess())
            ->activeWhen(fn (): bool => original_request()->routeIs(
                array_map(fn (string $route): string => EventResource::getRouteBaseName().'.'.$route, $activeRoutes),
            ))
            ->url(fn (): ?string => ($id = self::eventId()) === null ? null : EventResource::getUrl($page, ['record' => $id]));
    }

    /** Evento de la ruta: el parámetro `event` de los recursos anidados o `record` de las páginas del propio evento. */
    public static function eventId(): ?string
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
