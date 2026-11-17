<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Submissions\Tables\SubmissionsTable;
use App\Models\Submission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Propuestas del evento actual (plan 009, H-009-1). Vive bajo el evento
 * (`/{tenant}/events/{event}/submissions`): el evento de la ruta se resuelve con la
 * consulta de `EventResource`, que pasa por el scope de organización, así que un
 * evento de otra organización responde 404 (DA-6). La lista es la página del evento
 * `ManageEventSubmissions` (plan 020, F2); este recurso aporta la tabla y, con el
 * detalle de H-009-2, las páginas de cada propuesta. Solo lectura: sin crear, editar
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

    /** @return array<string, mixed> sin páginas propias todavía: el detalle llega con H-009-2 */
    public static function getPages(): array
    {
        return [];
    }
}
