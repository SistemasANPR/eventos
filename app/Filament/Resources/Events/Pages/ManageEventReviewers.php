<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Enums\ReviewAssignmentScope;
use App\Enums\ReviewAssignmentStatus;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Submissions\Actions\ReviewAssignmentActions;
use App\Models\Event;
use App\Models\ReviewAssignment;
use App\Support\Reviews\ReviewerProgress;
use App\Support\Reviews\ScopeCoverage;
use App\Support\Reviews\ScopeCoverageRow;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use LogicException;

/**
 * Evaluadores del evento y su asignación (plan 011, F3b, CA-011-48): la vista «por evaluador» de la pantalla de
 * asignación. Por evaluador muestra cuántas propuestas tiene asignadas y cuántas ya envió, y sobre la tabla
 * lista los ejes y convocatorias con cobertura incompleta (menos evaluadores que el mínimo de la rúbrica). El
 * avance completo (porcentaje, desglose, filtros) es de F6 y no está aquí. Desde aquí se invita a un evaluador,
 * se asigna por eje o convocatoria y se aplican las reglas de alcance. El evento sale de la ruta, así que un
 * evento de otra organización responde 404; entra quien tiene `review_assignments.manage`. Delgado: las reglas
 * viven en las Actions.
 */
class ManageEventReviewers extends ManageRelatedRecords
{
    protected static string $resource = EventResource::class;

    protected static string $relationship = 'reviewerAssignments';

    /** @var array<string, ReviewerProgress>|null avance por evaluador, calculado una vez por petición */
    private ?array $progressByReviewer = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    public static function getNavigationLabel(): string
    {
        return __('review_assignments.reviewers.navigation');
    }

    /** La sección se llama como su entrada de menú, no «Administrar Asignaciones de evaluadores Evento». */
    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    /**
     * Ver la pantalla exige el permiso de asignar. La comprobación de Filament por defecto miraría la Policy de
     * `event_assignments` (solo del administrador) y dejaría fuera al organizador, que también asigna.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function canAccess(array $parameters = []): bool
    {
        return Gate::allows('viewAny', ReviewAssignment::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('user')
                ->addSelect(['assigned_count' => $this->assignmentsCount()]))
            ->heading(__('review_assignments.reviewers.heading'))
            ->description(fn (): Htmlable => $this->coverageNote())
            ->columns([
                TextColumn::make('user.name')->label(__('review_assignments.reviewers.columns.name'))->weight('medium'),
                TextColumn::make('user.email')->label(__('review_assignments.reviewers.columns.email'))->toggleable(),
                TextColumn::make('assigned_count')
                    ->label(__('review_assignments.reviewers.columns.assigned'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('progress')
                    ->label(__('review_assignments.reviewers.columns.progress'))
                    ->state(fn (Model $record): string => $this->progress($record)),
            ])
            ->defaultSort('created_at')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading(__('review_assignments.reviewers.empty_heading'))
            ->emptyStateDescription(__('review_assignments.reviewers.empty_description'));
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            ReviewAssignmentActions::invite(),
            ReviewAssignmentActions::assignByScope(),
            ReviewAssignmentActions::syncNew(),
            Action::make('rules')
                ->label(__('review_assignments.rules.navigation'))
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->color('gray')
                ->visible(fn (): bool => Gate::allows('viewAny', ReviewAssignment::class))
                ->url(fn (): string => EventResource::getUrl('assignment-rules', ['record' => $this->event()])),
        ];
    }

    /**
     * Asignaciones activas del evaluador de la fila en este evento (ni canceladas ni de conflicto).
     *
     * @return Builder<ReviewAssignment>
     */
    private function assignmentsCount(): Builder
    {
        return ReviewAssignment::query()
            ->selectRaw('count(*)')
            ->whereColumn('review_assignments.reviewer_id', 'event_assignments.user_id')
            ->whereColumn('review_assignments.event_id', 'event_assignments.event_id')
            ->whereIn('review_assignments.status', ReviewAssignmentStatus::coverageValues());
    }

    /** El avance sale de `ReviewerProgress`, el mismo cálculo de la página de avance (CA-011-25 y 56). */
    private function progress(Model $record): string
    {
        $this->progressByReviewer ??= ReviewerProgress::forEvent($this->event());

        return ($this->progressByReviewer[(string) $record->getAttribute('user_id')] ?? new ReviewerProgress)->label();
    }

    /** Los ejes y convocatorias con cobertura incompleta, o la nota de que no hay ninguno. Cada nombre se escapa. */
    private function coverageNote(): Htmlable
    {
        $rows = ScopeCoverage::incompleteFor($this->event());

        if ($rows === []) {
            return new HtmlString(e(__('review_assignments.reviewers.coverage_ok')));
        }

        $items = array_map(fn (ScopeCoverageRow $row): string => '<li>'.e(__('review_assignments.reviewers.coverage_item', [
            'scope' => __($row->scope === ReviewAssignmentScope::Track ? 'review_assignments.form.scope_track' : 'review_assignments.form.scope_call'),
            'name' => $row->name,
            'incomplete' => $row->incompleteSubmissions,
            'total' => $row->totalSubmissions,
        ])).'</li>', $rows);

        return new HtmlString('<strong>'.e(__('review_assignments.columns.coverage_incomplete')).'</strong><ul class="list-disc ps-5">'.implode('', $items).'</ul>');
    }

    private function event(): Event
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Event ? $owner : throw new LogicException('Los evaluadores necesitan un evento.');
    }
}
