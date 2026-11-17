<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions;

use App\Enums\SubmissionOrigin;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Resources\Submissions\Tables\SubmissionsTable;
use App\Models\Submission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Propuestas del evento actual (plan 009, H-009-1). Vive bajo el evento
 * (`/{tenant}/events/{event}/submissions`): el evento de la ruta se resuelve con la
 * consulta de `EventResource`, que pasa por el scope de organización, así que un
 * evento de otra organización responde 404 (DA-6). Solo lectura: sin crear, editar
 * ni borrar desde aquí. La Policy decide quién ve.
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
        return parent::getEloquentQuery()
            ->where('origin', SubmissionOrigin::Call)
            ->with(['event', 'call', 'submissionType', 'track']);
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return [
            'index' => ListSubmissions::route('/'),
        ];
    }
}
