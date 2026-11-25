<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events;

use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\EditEventLanding;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Pages\ManageEventActivities;
use App\Filament\Resources\Events\Pages\ManageEventActivityCategories;
use App\Filament\Resources\Events\Pages\ManageEventAssignmentRules;
use App\Filament\Resources\Events\Pages\ManageEventFormDefinitions;
use App\Filament\Resources\Events\Pages\ManageEventLodgings;
use App\Filament\Resources\Events\Pages\ManageEventReviewers;
use App\Filament\Resources\Events\Pages\ManageEventRubrics;
use App\Filament\Resources\Events\Pages\ManageEventSpeakerCategories;
use App\Filament\Resources\Events\Pages\ManageEventSpeakers;
use App\Filament\Resources\Events\Pages\ManageEventSponsors;
use App\Filament\Resources\Events\Pages\ManageEventSubmissions;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\Pages\ViewEventReviewerProgress;
use App\Filament\Resources\Events\Pages\ViewEventReviewerProgressDetail;
use App\Filament\Resources\Events\Pages\ViewEventReviewResults;
use App\Filament\Resources\Events\RelationManagers\CallsRelationManager;
use App\Filament\Resources\Events\RelationManagers\PrivacyNoticesRelationManager;
use App\Filament\Resources\Events\RelationManagers\SubmissionTypesRelationManager;
use App\Filament\Resources\Events\RelationManagers\TracksRelationManager;
use App\Filament\Resources\Events\Schemas\EventForm;
use App\Filament\Resources\Events\Schemas\EventInfolist;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Models\Event;
use BackedEnum;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Eventos de la organización (plan 003, H-003-1). La organización es el tenant
 * del panel: la consulta pasa por el scope global de `BelongsToOrganization` y
 * un evento de otra organización responde 404 en su URL. No se ofrece borrar:
 * un evento se archiva (nunca se elimina físicamente).
 */
class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('events.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('events.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('events.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return EventForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EventInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EventsTable::configure($table);
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [
            CallsRelationManager::class,
            SubmissionTypesRelationManager::class,
            TracksRelationManager::class,
            PrivacyNoticesRelationManager::class,
        ];
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'create' => CreateEvent::route('/create'),
            'view' => ViewEvent::route('/{record}'),
            'edit' => EditEvent::route('/{record}/edit'),
            // Editor visual de la landing del evento (plan 022, F4).
            'landing' => EditEventLanding::route('/{record}/landing'),
            // Nombre = ruta del recurso anidado (`FormDefinitionResource`): Filament lo usa como su índice.
            'form-definitions' => ManageEventFormDefinitions::route('/{record}/form-definitions'),
            'submissions' => ManageEventSubmissions::route('/{record}/submissions'),
            // Evaluadores del evento y sus reglas permanentes de alcance (plan 011, F3b).
            'reviewers' => ManageEventReviewers::route('/{record}/reviewers'),
            'assignment-rules' => ManageEventAssignmentRules::route('/{record}/assignment-rules'),
            // Rúbricas de evaluación del evento (plan 011, F4b); nombre = ruta del recurso anidado `RubricResource`.
            'rubrics' => ManageEventRubrics::route('/{record}/rubrics'),
            // Resultados, avance por evaluador (con su detalle) y cierre del periodo de evaluación (plan 011, F6).
            'review-results' => ViewEventReviewResults::route('/{record}/review-results'),
            'review-progress' => ViewEventReviewerProgress::route('/{record}/review-progress'),
            'review-progress-detail' => ViewEventReviewerProgressDetail::route('/{record}/review-progress/{reviewer}'),
            // Ponentes del evento (plan 021, F2).
            'speakers' => ManageEventSpeakers::route('/{record}/speakers'),
            // Actividades del programa (plan 021, F3).
            'activities' => ManageEventActivities::route('/{record}/activities'),
            // Hospedaje y patrocinadores del evento (plan 030, F2).
            'lodgings' => ManageEventLodgings::route('/{record}/lodgings'),
            'sponsors' => ManageEventSponsors::route('/{record}/sponsors'),
            // Catálogos de categorías del programa (plan 021, F1).
            'speaker-categories' => ManageEventSpeakerCategories::route('/{record}/speaker-categories'),
            'activity-categories' => ManageEventActivityCategories::route('/{record}/activity-categories'),
        ];
    }

    /**
     * «Eventos» y, dentro de un evento, sus secciones anidadas en el menú lateral (plan 020, DA-1
     * revisada el 2026-11-17: submenú en el aside azul, no una columna dentro de la página).
     *
     * @return array<int, NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        return [...parent::getNavigationItems(), ...EventSectionsNavigation::items(static::class)];
    }
}
