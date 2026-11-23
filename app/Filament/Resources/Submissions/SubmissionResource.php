<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions;

use App\Enums\SubmissionOrigin;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Submissions\Pages\ViewSubmission;
use App\Filament\Resources\Submissions\Tables\SubmissionsTable;
use App\Models\Submission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Propuestas del evento actual (plan 009, H-009-1). Vive bajo el evento
 * (`/{tenant}/events/{event}/submissions`): el evento de la ruta se resuelve con la
 * consulta de `EventResource`, que pasa por el scope de organización, así que un
 * evento de otra organización responde 404 (DA-6). La lista es la página del evento
 * `ManageEventSubmissions` (plan 020, F2); este recurso aporta la tabla y, con el
 * detalle de H-009-2, las páginas de cada propuesta. Sin crear, editar ni borrar desde
 * aquí; el estado cambia solo con las acciones de decisión del detalle (plan 012). La Policy
 * decide quién ve, quién decide y quién revierte.
 */
class SubmissionResource extends Resource
{
    protected static ?string $model = Submission::class;

    protected static ?string $parentResource = EventResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    public static function getNavigationLabel(): string
    {
        return __('submission_panel.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('submission_panel.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('submission_panel.plural');
    }

    public static function table(Table $table): Table
    {
        return SubmissionsTable::configure($table);
    }

    /**
     * Solo las propuestas de la convocatoria: los invitados (plan 018) tienen su propia vista y
     * nunca entran en esta lista. Carga lo que muestra la tabla para evitar N+1.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return self::scopeToList(parent::getEloquentQuery());
    }

    /**
     * Las reglas de la lista, aplicables a una consulta o a una relación: la página del evento
     * (`ManageEventSubmissions`) parte de `$evento->submissions()` y no pasa por `getEloquentQuery()`.
     *
     * @template TQuery of Builder|Relation
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function scopeToList(Builder|Relation $query): Builder|Relation
    {
        return $query
            ->where('origin', SubmissionOrigin::Call)
            ->with(['event', 'call', 'submissionType', 'track', 'activity']);
    }

    /**
     * El detalle abre también a un invitado (marca «Invitado», plan 018): la ruta de una propuesta
     * no se restringe por origen, solo por organización y evento de la ruta.
     *
     * @return Builder<Model>
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['event', 'call', 'submissionType', 'track', 'participants']);
    }

    /**
     * Solo el detalle (H-009-2). La lista es la sección `submissions` del evento (`ManageEventSubmissions`,
     * plan 020): Filament la usa como índice de este recurso anidado.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'view' => ViewSubmission::route('/{record}'),
        ];
    }
}
