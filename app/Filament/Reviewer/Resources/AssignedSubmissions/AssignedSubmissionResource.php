<?php

declare(strict_types=1);

namespace App\Filament\Reviewer\Resources\AssignedSubmissions;

use App\Filament\Reviewer\Resources\AssignedSubmissions\Pages\EvaluateSubmission;
use App\Filament\Reviewer\Resources\AssignedSubmissions\Pages\ListAssignedSubmissions;
use App\Filament\Reviewer\Resources\AssignedSubmissions\Tables\AssignedSubmissionsTable;
use App\Filament\Support\ActionErrors;
use App\Models\Review;
use App\Models\ReviewAssignment;
use App\Support\Reviews\ReviewerAssignments;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Propuestas asignadas al evaluador (plan 011, H-011-3, CA-011-11, 12 y 50 a 53). El modelo es la
 * asignación (`ReviewAssignment`): la propuesta se evalúa a través de ella. Toda consulta del recurso
 * parte de `ReviewerAssignments`, así que una asignación ajena, cancelada o de otro evento no existe para
 * el evaluador y su identificador responde 404. La Policy es `ReviewPolicy` (no hay permiso de organizador
 * que valga aquí): el recurso la consulta en vez de la `ReviewAssignmentPolicy` de los organizadores.
 */
class AssignedSubmissionResource extends Resource
{
    protected static ?string $model = ReviewAssignment::class;

    protected static ?string $slug = 'assigned-submissions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function getNavigationLabel(): string
    {
        return __('reviewer.list.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('reviewer.list.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reviewer.list.plural');
    }

    public static function table(Table $table): Table
    {
        return AssignedSubmissionsTable::configure($table);
    }

    /** @return Builder<ReviewAssignment> */
    public static function getEloquentQuery(): Builder
    {
        return ReviewerAssignments::for(ActionErrors::actor())
            ->with(['event', 'submission.track', 'submission.call', 'submission.submissionType', 'submission.participants']);
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAnyAssignments', Review::class);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof ReviewAssignment && Gate::allows('viewAssignment', [Review::class, $record]);
    }

    /** La página de cada propuesta (`EvaluateSubmission`) la abre quien puede verla; calificar lo decide `submit`. */
    public static function canEdit(Model $record): bool
    {
        return self::canView($record);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return [
            'index' => ListAssignedSubmissions::route('/'),
            'evaluate' => EvaluateSubmission::route('/{record}'),
        ];
    }
}
