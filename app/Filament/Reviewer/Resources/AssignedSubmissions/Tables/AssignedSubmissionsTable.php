<?php

declare(strict_types=1);

namespace App\Filament\Reviewer\Resources\AssignedSubmissions\Tables;

use App\Enums\ReviewAssignmentStatus;
use App\Filament\Reviewer\Resources\AssignedSubmissions\AssignedSubmissionResource;
use App\Filament\Support\ActionErrors;
use App\Models\CallForSubmissions;
use App\Models\Review;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\Track;
use App\Support\Reviews\ReviewerAssignments;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Tabla de las propuestas asignadas al evaluador (plan 011, CA-011-51 y 53): paginada en el servidor con
 * tope de 100 (AGENTS.md), las pendientes primero y filtros por estado, eje y convocatoria. Las opciones de
 * los filtros salen solo de SUS asignaciones; un valor que no está entre ellas no filtra nada y no revela si
 * existe. La acción de calificar solo aparece donde procede (ni en conflicto declarado ni con el periodo
 * cerrado); ocultarla no autoriza nada: `SubmitReview` vuelve a comprobarlo.
 */
final class AssignedSubmissionsTable
{
    /** Tope de filas por página; AGENTS.md: listas paginadas, máximo 100. */
    public const MAX_PER_PAGE = 100;

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => ReviewerAssignments::pendingFirst($query))
            ->columns([
                TextColumn::make('submission.title')
                    ->label(__('reviewer.list.columns.title'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('event')
                    ->label(__('reviewer.list.columns.event'))
                    ->state(fn (ReviewAssignment $record): string => (string) $record->event->getTranslation('name', app()->getLocale(), true)),
                TextColumn::make('track')
                    ->label(__('reviewer.list.columns.track'))
                    ->state(fn (ReviewAssignment $record): string => (string) $record->submission->track->getTranslation('name', app()->getLocale(), true)),
                TextColumn::make('call')
                    ->label(__('reviewer.list.columns.call'))
                    ->state(fn (ReviewAssignment $record): string => (string) $record->submission->call?->getTranslation('title', app()->getLocale(), true)),
                TextColumn::make('status')
                    ->label(__('reviewer.list.columns.status'))
                    ->badge(),
            ])
            ->recordUrl(fn (ReviewAssignment $record): string => AssignedSubmissionResource::getUrl('evaluate', ['record' => $record]))
            ->recordActions([self::reviewAction()])
            ->filters([
                self::statusFilter(),
                self::relationFilter('track', 'track_id', self::trackOptions(...)),
                self::relationFilter('call', 'call_for_submissions_id', self::callOptions(...)),
            ])
            ->paginated([10, 25, 50, self::MAX_PER_PAGE])
            ->defaultPaginationPageOption(25)
            // Sin esto Filament acepta cualquier tamaño que llegue del cliente.
            ->enforcePaginationPageOptions()
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentList)
            ->emptyStateHeading(__('reviewer.list.empty_heading'))
            ->emptyStateDescription(__('reviewer.list.empty_description'));
    }

    /** «Calificar», «Continuar» o «Reevaluar» según el estado; solo si se puede calificar y el periodo está abierto. */
    private static function reviewAction(): Action
    {
        return Action::make('review')
            ->label(fn (ReviewAssignment $record): string => __('reviewer.list.actions.'.match ($record->status) {
                ReviewAssignmentStatus::Pending => 'review',
                ReviewAssignmentStatus::InProgress => 'continue',
                default => 'review_again',
            }))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->url(fn (ReviewAssignment $record): string => AssignedSubmissionResource::getUrl('evaluate', ['record' => $record]))
            ->visible(fn (ReviewAssignment $record): bool => $record->event->reviewPeriodIsOpen()
                && Gate::allows('submit', [Review::class, $record]));
    }

    private static function statusFilter(): SelectFilter
    {
        // La cancelada no aparece nunca, así que tampoco se ofrece como filtro.
        $options = collect(ReviewAssignmentStatus::cases())
            ->reject(static fn (ReviewAssignmentStatus $status): bool => $status === ReviewAssignmentStatus::Cancelled)
            ->mapWithKeys(static fn (ReviewAssignmentStatus $status): array => [$status->value => $status->getLabel()])
            ->all();

        return SelectFilter::make('status')
            ->label(__('reviewer.list.columns.status'))
            ->options($options)
            ->query(function (Builder $query, array $data) use ($options): Builder {
                $value = $data['value'] ?? null;

                if (blank($value)) {
                    return $query;
                }

                return array_key_exists((string) $value, $options) ? $query->where('review_assignments.status', $value) : $query->whereKey([]);
            });
    }

    /** @param  callable(): array<string, string>  $options */
    private static function relationFilter(string $name, string $column, callable $options): SelectFilter
    {
        return SelectFilter::make($name)
            ->label(__("reviewer.list.columns.{$name}"))
            ->options(fn (): array => $options())
            ->query(function (Builder $query, array $data) use ($column, $options): Builder {
                $value = $data['value'] ?? null;

                if (blank($value)) {
                    return $query;
                }

                return array_key_exists((string) $value, $options())
                    ? $query->whereHas('submission', fn (Builder $submissions): Builder => $submissions->where($column, $value))
                    : $query->whereKey([]);
            });
    }

    /** @return array<string, string> ejes de sus asignaciones */
    private static function trackOptions(): array
    {
        return Track::query()
            ->whereIn('id', self::assignedSubmissions()->select('track_id'))
            ->get()
            ->mapWithKeys(static fn (Track $track): array => [$track->id => (string) $track->getTranslation('name', app()->getLocale(), true)])
            ->all();
    }

    /** @return array<string, string> convocatorias de sus asignaciones */
    private static function callOptions(): array
    {
        return CallForSubmissions::query()
            ->whereIn('id', self::assignedSubmissions()->select('call_for_submissions_id'))
            ->get()
            ->mapWithKeys(static fn (CallForSubmissions $call): array => [$call->id => (string) $call->getTranslation('title', app()->getLocale(), true)])
            ->all();
    }

    /** @return Builder<Submission> */
    private static function assignedSubmissions(): Builder
    {
        return Submission::query()->whereIn('id', ReviewerAssignments::for(ActionErrors::actor())->select('review_assignments.submission_id'));
    }
}
